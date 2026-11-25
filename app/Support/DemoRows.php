<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\DemoLimitReached;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientReport;
use App\Models\ClientRetainer;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadStageEvent;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskLink;
use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The public demo's shared account: a signed-in visitor adds a set number of rows past the seeded ones and cannot delete a sample record.
 */
final class DemoRows
{
    public const string SCOPE = 'demo_baseline';

    /** @var list<class-string<Model>> */
    public const array KEPT = [
        Client::class, Contact::class, Lead::class, LeadStageEvent::class, Project::class, Repository::class, Task::class, TaskLink::class,
        TimeEntry::class, ClientReport::class, ClientRetainer::class, ClientDocument::class, TaskAttachment::class,
    ];

    public static function record(Account $account): void
    {
        $last = [];
        foreach (Schema::getTableListing(Schema::getCurrentSchemaName(), schemaQualified: false) as $table) {
            if (Schema::hasColumn($table, 'id')) {
                $last[$table] = (int) DB::table($table)->max('id');
            }
        }

        $row = Setting::query()->updateOrCreate(['account_id' => $account->id, 'scope' => self::SCOPE], ['data' => $last]);
        $row->update(['data' => [...$last, $row->getTable() => (int) DB::table($row->getTable())->max('id')]]);
    }

    /** @throws DemoLimitReached */
    public static function guardCreate(Model $record): void
    {
        if (! self::held() || ! $record->getIncrementing() || $record->getKeyName() !== 'id') {
            return;
        }

        $table = $record->getTable();
        $last = self::last($table);
        if ($last === null || DB::table($table)->where('id', '>', $last)->count() >= self::room($table)) {
            throw DemoLimitReached::full();
        }
    }

    /** @throws DemoLimitReached */
    public static function guardDelete(Model $record): void
    {
        if (! self::held() || ! in_array($record::class, self::KEPT, true)) {
            return;
        }

        $last = self::last($record->getTable());
        if ($last === null || (int) $record->getKey() <= $last) {
            throw DemoLimitReached::sample();
        }
    }

    private static function held(): bool
    {
        return (bool) config('app.demo') && Auth::check();
    }

    /** Null for a demo with no baseline: it has no room and every row is a sample, so a missed reseed fails closed. */
    private static function last(string $table): ?int
    {
        $baseline = Setting::query()->where('scope', self::SCOPE)->value('data');

        return is_array($baseline) ? (int) ($baseline[$table] ?? 0) : null;
    }

    private static function room(string $table): int
    {
        return (int) (config("app.demo_rows.tables.{$table}") ?? config('app.demo_rows.per_table'));
    }
}
