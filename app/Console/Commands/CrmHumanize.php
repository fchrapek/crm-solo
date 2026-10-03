<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Models\ClientReport;
use App\Models\ClientReportRevision;
use App\Services\Humanizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill companion to the HumanizedText cast: runs the same deterministic
 * humanizer over EXISTING content (em/en dashes, curly quotes, ellipsis
 * chars, invisible artifacts). New writes are gated by the cast; this cleans
 * what got in before the gate existed. Idempotent.
 *
 * It only reports by default; --write applies. A report body is changed
 * through the same revision path as a manual edit, so the old text stays in
 * the report's change history.
 */
#[AccountScope(AccountScope::OPERATOR)]
final class CrmHumanize extends Command
{
    /** table => content columns (mirror of the HumanizedText cast wiring). */
    private const TARGETS = [
        'clients' => ['notes', 'report_baseline_markdown', 'maintenance_invoice_description'],
        'client_lifecycle_events' => ['note'],
        'tasks' => ['description'], // manual tasks only: a card's description is the client's text, kept as Trello has it
        'time_entries' => ['title', 'description'],
        'leads' => ['notes'],
        'lead_stage_events' => ['note'],
        'client_reports' => ['body_markdown'],
    ];

    protected $signature = 'crm:humanize {--write : Apply the changes; without it the command only lists what would change}';

    protected $description = 'Backfill the humanizer over existing content (dashes, curly quotes, invisible chars); a dry run unless --write (operator: every account)';

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        $totalChanged = 0;

        foreach (self::TARGETS as $table => $columns) {
            $changedInTable = 0;
            DB::table($table)
                ->select(['id', ...$columns])
                ->when($table === 'tasks', fn ($q) => $q->whereNull('trello_card_id'))
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($table, $columns, $write, &$changedInTable): void {
                    foreach ($rows as $row) {
                        $updates = [];
                        foreach ($columns as $column) {
                            $original = $row->{$column};
                            if (! is_string($original) || $original === '') {
                                continue;
                            }
                            $clean = Humanizer::clean($original);
                            if ($clean !== $original) {
                                $updates[$column] = $clean;
                            }
                        }
                        if ($updates === []) {
                            continue;
                        }

                        if ($write) {
                            $updates = $this->apply($table, (int) $row->id, $columns);
                            if ($updates === []) {
                                continue;
                            }
                        }

                        $changedInTable++;
                        $this->line(sprintf('%s%s #%d: %s', $write ? '' : '[dry] ', $table, $row->id, implode(', ', array_keys($updates))));
                    }
                });

            $totalChanged += $changedInTable;
        }

        if ($totalChanged === 0) {
            $this->info('Content already clean.');
        } elseif ($write) {
            $this->info(sprintf('%d row(s) humanized.', $totalChanged));
        } else {
            $this->info(sprintf('[dry] %d row(s) would change. Run again with --write to apply.', $totalChanged));
        }

        return self::SUCCESS;
    }

    /**
     * Clean the row's current values under a row lock, so text saved after the
     * scan read the row is what gets cleaned, never overwritten with older text.
     *
     * @param  list<string>  $columns
     * @return array<string, string> the columns that changed
     */
    private function apply(string $table, int $id, array $columns): array
    {
        return DB::transaction(function () use ($table, $id, $columns): array {
            $current = $table === 'client_reports'
                ? ClientReport::query()->lockForUpdate()->find($id)
                : DB::table($table)->where('id', $id)->when($table === 'tasks', fn ($q) => $q->whereNull('trello_card_id'))->lockForUpdate()->first();
            if ($current === null) {
                return [];
            }

            $updates = [];
            foreach ($columns as $column) {
                $value = $table === 'client_reports' ? $current->getRawOriginal($column) : $current->{$column};
                if (is_string($value) && $value !== '' && ($clean = Humanizer::clean($value)) !== $value) {
                    $updates[$column] = $clean;
                }
            }
            if ($updates === []) {
                return [];
            }

            if ($current instanceof ClientReport) {
                $current->recordRevision(ClientReportRevision::REASON_UPDATE);
                $current->update($updates);
            } else {
                DB::table($table)->where('id', $id)->update($updates);
            }

            return $updates;
        });
    }
}
