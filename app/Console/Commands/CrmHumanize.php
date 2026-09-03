<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Humanizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill companion to the HumanizedText cast: runs the same
 * deterministic humanizer over EXISTING content (em/en dashes, curly quotes,
 * ellipsis chars, invisible artifacts). New writes are gated by the cast;
 * this cleans what got in before the gate existed. Idempotent.
 */
final class CrmHumanize extends Command
{
    /** table => content columns (mirror of the HumanizedText cast wiring). */
    private const TARGETS = [
        'clients' => ['notes', 'report_baseline_markdown', 'maintenance_invoice_description'],
        'client_lifecycle_events' => ['note'],
        'tasks' => ['description'],
        'time_entries' => ['title', 'description'],
        'leads' => ['notes'],
        'lead_stage_events' => ['note'],
        'client_reports' => ['body_markdown'],
    ];

    protected $signature = 'crm:humanize {--dry-run : Report what would change without writing}';

    protected $description = 'Backfill the humanizer over existing content (dashes, curly quotes, invisible chars)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $totalChanged = 0;

        foreach (self::TARGETS as $table => $columns) {
            $changedInTable = 0;
            DB::table($table)
                ->select(['id', ...$columns])
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($table, $columns, $dry, &$changedInTable): void {
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
                        if ($updates !== []) {
                            $changedInTable++;
                            if (! $dry) {
                                DB::table($table)->where('id', $row->id)->update($updates);
                            }
                        }
                    }
                });

            if ($changedInTable > 0) {
                $this->line(sprintf('%s%s: %d row(s)', $dry ? '[dry] ' : '', $table, $changedInTable));
            }
            $totalChanged += $changedInTable;
        }

        $this->info($totalChanged === 0
            ? 'Content already clean.'
            : sprintf('%s%d row(s) humanized.', $dry ? '[dry] would change ' : '', $totalChanged));

        return self::SUCCESS;
    }
}
