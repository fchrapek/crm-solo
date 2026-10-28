<?php

declare(strict_types=1);

namespace App\Services\Time;

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\Agent\TimeEntryRecord;
use App\Services\Clients\ClientFinancialLock;
use App\Services\Clients\SettlementPolicy;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The one create, edit, split and delete of logged time after the fact, for
 * the web and the agents; callers check who may change what. In a settled
 * period the numbers are locked (time, task, billable, new and deleted
 * entries) while the title and description stay editable.
 */
final class TimeEntryWriter
{
    /** Columns whose change since the caller read the entry makes its edit stale. */
    private const array READ_COLUMNS = ['client_id', 'task_id', 'start_time', 'end_time', 'duration_minutes'];

    /** The changes a settled period refuses; title and description stay open. */
    private const array NUMBERS = ['task', 'billable', 'start', 'end', 'minutes'];

    public function __construct(
        private readonly ClientFinancialLock $lock,
        private readonly SettlementPolicy $policy,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  a full TimeEntry row
     *
     * @throws PeriodSettled
     */
    public function create(array $attributes): TimeEntry
    {
        $client = isset($attributes['client_id']) ? (int) $attributes['client_id'] : null;

        return $this->lock->clients($client !== null ? [$client] : [], function () use ($attributes, $client): TimeEntry {
            $this->refuseSettled([[$client, Carbon::instance($attributes['start_time'])]]);

            return TimeEntry::create($attributes);
        });
    }

    /**
     * Only the keys present change. `end` sets the end and recounts the
     * minutes; without it, `minutes` moves the end from the start.
     *
     * @param  array{title?: ?string, description?: ?string, task?: ?Task, billable?: bool, start?: CarbonInterface, end?: ?CarbonInterface, minutes?: ?int}  $changes
     * @return TimeEntry the entry as written
     *
     * @throws PeriodSettled|TimeEntryChanged
     */
    public function update(TimeEntry $entry, array $changes): TimeEntry
    {
        $task = $changes['task'] ?? null;
        $client = $task !== null ? self::clientOf($task) : $entry->client_id;

        return $this->locked($entry, [$client], function (TimeEntry $entry) use ($changes, $client): TimeEntry {
            $changes = self::withoutHeldNumbers($entry, $changes);
            if (array_intersect_key($changes, array_flip(self::NUMBERS)) !== []) {
                $this->refuseSettled([[$entry->client_id, $entry->start_time], [$client, $changes['start'] ?? $entry->start_time]]);
            }
            $this->apply($entry, $changes);

            return $entry;
        });
    }

    /**
     * Moves a task's time onto the task's project and client, entry by entry,
     * running ones included; an entry in a settled period stays where it is.
     *
     * @return array{moved: list<TimeEntry>, skipped: list<array{entry: TimeEntry, settled: PeriodSettled}>}
     *
     * @throws TimeEntryChanged
     */
    public function followTask(Task $task): array
    {
        $result = ['moved' => [], 'skipped' => []];
        $entries = TimeEntry::query()->where('task_id', $task->id)
            ->where(fn ($query) => $query->whereNull('project_id')->orWhere('project_id', '!=', $task->project_id))
            ->orderBy('start_time')->get();

        $client = self::clientOf($task);
        foreach ($entries as $entry) {
            try {
                $result['moved'][] = $this->locked($entry, [$client], function (TimeEntry $entry) use ($task, $client): TimeEntry {
                    $this->refuseSettled([[$entry->client_id, $entry->start_time], [$client, $entry->start_time]]);
                    $this->assignTask($entry, $task);
                    $entry->save();

                    return $entry;
                });
            } catch (PeriodSettled $settled) {
                $result['skipped'][] = ['entry' => $entry, 'settled' => $settled];
            }
        }

        return $result;
    }

    /** @throws PeriodSettled|TimeEntryChanged */
    public function delete(TimeEntry $entry): void
    {
        $this->locked($entry, [], function (TimeEntry $entry): void {
            $this->refuseSettled([[$entry->client_id, $entry->start_time]]);
            $entry->delete();
        });
    }

    /**
     * Deletes projects and, through Project::deleting, their tasks, holding every client their tasks' time counts for
     * before anything else is read.
     *
     * @param  Collection<int, Project>  $projects
     *
     * @throws PeriodSettled when a task's time is settled; nothing is deleted
     */
    public function deleteProjects(Collection $projects): void
    {
        $tasks = Task::query()->whereIn('project_id', $projects->pluck('id'))->select('id');
        $clients = $projects->pluck('client_id')
            ->merge(TimeEntry::query()->whereIn('task_id', $tasks)->distinct()->pluck('client_id'))
            ->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();

        $this->lock->clients($clients, fn () => $projects->each(fn (Project $project) => $project->delete()));
    }

    /**
     * Unlinks a task's logged time as the task is deleted; the entries keep their project and client.
     *
     * @throws PeriodSettled when any of it is settled, so the task is archived instead
     */
    public function detachTask(Task $task): void
    {
        $logged = TimeEntry::query()->where('task_id', $task->id);
        $clientsOf = fn (iterable $ids): array => collect($ids)->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $clients = $clientsOf((clone $logged)->distinct()->pluck('client_id'));

        do {
            // Time logged for another client since the read above is only checked once that client is locked too.
            $unlocked = $this->lock->clients($clients, function () use ($logged, $clientsOf, &$clients): bool {
                $entries = (clone $logged)->lockForUpdate()->get(['id', 'client_id', 'start_time']);
                $found = $clientsOf($entries->pluck('client_id'));
                if (array_diff($found, $clients) !== []) {
                    $clients = array_values(array_unique([...$clients, ...$found]));

                    return true;
                }
                $this->refuseSettled($entries->map(fn (TimeEntry $entry): array => [$entry->client_id, $entry->start_time])->all());
                (clone $logged)->update(['task_id' => null]);

                return false;
            });
        } while ($unlocked);
    }

    /**
     * Splits a stopped entry into parts laid end to end from its start. With
     * minutes left over and $dropRest false, the entry keeps its task and
     * description and becomes the last stretch; otherwise it becomes the
     * first part and what is left over is no longer logged.
     *
     * @param  non-empty-list<array{task: Task, minutes: int, description: ?string}>  $parts
     * @return list<TimeEntry> the parts in order, then the entry when it keeps the rest
     *
     * @throws PeriodSettled|TimeEntryChanged
     */
    public function split(TimeEntry $entry, array $parts, bool $dropRest): array
    {
        if ($entry->end_time === null) {
            throw new InvalidArgumentException('A running entry cannot be split.');
        }
        $sum = array_sum(array_column($parts, 'minutes'));
        if ($parts === [] || min(array_column($parts, 'minutes')) < 1 || $sum > (int) $entry->duration_minutes) {
            throw new InvalidArgumentException('Each part needs at least one minute and the parts may not exceed the entry.');
        }
        $rest = (int) $entry->duration_minutes - $sum;
        $keepsRest = $rest > 0 && ! $dropRest;
        $clients = array_map(fn (array $part): ?int => self::clientOf($part['task']), $parts);

        return $this->locked($entry, $clients, function (TimeEntry $entry) use ($parts, $rest, $keepsRest): array {
            $points = [[$entry->client_id, $entry->start_time]];
            $cursor = $entry->start_time->copy();
            foreach ($parts as $part) {
                $points[] = [self::clientOf($part['task']), $cursor];
                $cursor = $cursor->copy()->addMinutes($part['minutes']);
            }
            if ($keepsRest) {
                $points[] = [$entry->client_id, $cursor];
            }
            $this->refuseSettled($points);

            $originalEnd = $entry->end_time->copy();
            $cursor = $entry->start_time->copy();
            $last = count($parts) - 1;
            $rows = [];
            foreach ($parts as $index => $part) {
                // Minutes are rounded up, so the stretch that reaches the end ends where the entry did.
                $end = $index === $last && $rest === 0 ? $originalEnd : $cursor->copy()->addMinutes($part['minutes']);
                $description = $part['description'] ?? $part['task']->name;
                if ($index === 0 && ! $keepsRest) {
                    $this->apply($entry, ['task' => $part['task'], 'description' => $description, 'start' => $cursor, 'end' => $end]);
                    $rows[] = $entry;
                } else {
                    $rows[] = TimeEntry::create([
                        'account_id' => $part['task']->project->account_id,
                        'project_id' => $part['task']->project_id,
                        'client_id' => $part['task']->project->client_id,
                        'task_id' => $part['task']->id,
                        'source' => $entry->source,
                        'description' => $description,
                        'start_time' => $cursor->copy(),
                        'end_time' => $end,
                        'duration_minutes' => $part['minutes'],
                        'billable' => $entry->billable,
                    ]);
                }
                $cursor = $cursor->copy()->addMinutes($part['minutes']);
            }
            if ($keepsRest) {
                $this->apply($entry, ['start' => $cursor, 'end' => $originalEnd]);
                $rows[] = $entry;
            }

            return $rows;
        });
    }

    /**
     * The changes without the numbers the entry already holds; times match to
     * the minute, as the dialog and the verbs write them.
     *
     * @param  array{title?: ?string, description?: ?string, task?: ?Task, billable?: bool, start?: CarbonInterface, end?: ?CarbonInterface, minutes?: ?int}  $changes
     * @return array{title?: ?string, description?: ?string, task?: ?Task, billable?: bool, start?: CarbonInterface, end?: ?CarbonInterface, minutes?: ?int}
     */
    private static function withoutHeldNumbers(TimeEntry $entry, array $changes): array
    {
        $sameMinute = fn (?CarbonInterface $stored, CarbonInterface $given): bool => $stored !== null
            && $stored->copy()->startOfMinute()->equalTo(Carbon::instance($given)->startOfMinute());
        $held = [
            'task' => fn (?Task $task): bool => $task?->id === ($entry->task_id !== null ? (int) $entry->task_id : null),
            'billable' => fn (bool $billable): bool => $billable === (bool) $entry->billable,
            'start' => fn (CarbonInterface $start): bool => $sameMinute($entry->start_time, $start),
            'end' => fn (CarbonInterface $end): bool => $sameMinute($entry->end_time, $end),
            'minutes' => fn (int $minutes): bool => $minutes === (int) $entry->duration_minutes,
        ];
        foreach ($held as $key => $holds) {
            if (! array_key_exists($key, $changes)) {
                continue;
            }
            // Minutes count from the start, so with a new start they name a new end.
            if (($changes[$key] === null && $key !== 'task') || ($holds($changes[$key]) && ($key !== 'minutes' || ! isset($changes['start'])))) {
                unset($changes[$key]);
            }
        }

        return $changes;
    }

    private static function clientOf(Task $task): ?int
    {
        $client = $task->loadMissing('project')->project?->client_id;

        return $client !== null ? (int) $client : null;
    }

    /** Eloquent saves a Carbon's wall clock, so an instant is converted to storage time first. */
    private static function stored(CarbonInterface $instant): Carbon
    {
        return Carbon::instance($instant)->setTimezone((string) config('app.timezone'));
    }

    /**
     * @param  array{title?: ?string, description?: ?string, task?: ?Task, billable?: bool, start?: CarbonInterface, end?: ?CarbonInterface, minutes?: ?int}  $changes
     */
    private function apply(TimeEntry $entry, array $changes): void
    {
        if (array_key_exists('title', $changes)) {
            $entry->title = $changes['title'];
        }
        if (array_key_exists('description', $changes)) {
            $entry->description = $changes['description'];
        }
        if (array_key_exists('task', $changes)) {
            $this->assignTask($entry, $changes['task']);
        }
        if (isset($changes['billable'])) {
            $entry->billable = $changes['billable'];
        }
        if (isset($changes['start'])) {
            $entry->start_time = self::stored($changes['start']);
        }
        if (isset($changes['end'])) {
            $end = self::stored($changes['end']);
            $entry->end_time = $end;
            $entry->duration_minutes = TimeEntry::minutesBetween($entry->start_time, $end);
        } elseif (isset($changes['minutes'])) {
            $entry->duration_minutes = $changes['minutes'];
            $entry->end_time = $entry->start_time->copy()->addMinutes($changes['minutes']);
        } elseif (isset($changes['start']) && $entry->end_time !== null) {
            $entry->duration_minutes = TimeEntry::minutesBetween($entry->start_time, $entry->end_time);
        }

        $entry->save();
    }

    /**
     * Runs $then on the entry re-read under the lock of its client and the
     * others named, once; an entry changed since the caller read it is refused.
     *
     * @template T
     *
     * @param  list<?int>  $clients
     * @param  Closure(TimeEntry): T  $then
     * @return T
     */
    private function locked(TimeEntry $entry, array $clients, Closure $then): mixed
    {
        $ids = array_values(array_unique(array_map('intval', array_filter([$entry->client_id, ...$clients], fn ($id): bool => $id !== null))));

        return $this->lock->clients($ids, function () use ($entry, $then): mixed {
            $fresh = TimeEntry::query()->lockForUpdate()->find($entry->id);
            foreach (self::READ_COLUMNS as $column) {
                if ($fresh === null || (string) $fresh->getRawOriginal($column) !== (string) $entry->getRawOriginal($column)) {
                    throw new TimeEntryChanged((int) $entry->id);
                }
            }

            return $then($fresh);
        });
    }

    /**
     * Refuses when any (client, start) pair lands on a settled day of that client.
     *
     * @param  list<array{0: mixed, 1: CarbonInterface}>  $points
     *
     * @throws PeriodSettled
     */
    private function refuseSettled(array $points): void
    {
        $points = array_values(array_filter($points, fn (array $point): bool => $point[0] !== null));
        $freezes = $this->policy->loggedTimeFreezes(array_values(array_unique(array_map(fn (array $point): int => (int) $point[0], $points))));

        foreach ($points as [$client, $start]) {
            $day = TimeEntryRecord::day($start);
            $settled = $freezes[(int) $client]->covering($day);
            if ($settled !== null) {
                throw new PeriodSettled((int) $client, $day, $settled['reason'], $settled['month']);
            }
        }
    }

    /** A task brings its project, client and account; no task keeps them. */
    private function assignTask(TimeEntry $entry, ?Task $task): void
    {
        if ($task === null) {
            $entry->task_id = null;

            return;
        }

        $task->loadMissing('project');
        $entry->task_id = $task->id;
        $entry->project_id = $task->project_id;
        $entry->client_id = $task->project->client_id;
        $entry->account_id = $task->project->account_id;
    }
}
