<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\TimeEntry;
use App\Services\Clients\SettlementPolicy;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The time entry list for `crm time-entries` and MCP `time_entries`, inside
 * one account, newest first. Days are local days and an entry counts on the
 * day it started, as reports count it.
 */
final class TimeEntryList
{
    private const int DEFAULT_LIMIT = 50;

    private const int MAX_LIMIT = 200;

    public function __construct(
        private readonly CrmEntityResolver $resolver,
        private readonly SettlementPolicy $policy,
    ) {}

    /**
     * @param  array{task?: ?string, client?: ?string, day?: ?string, from?: ?string, to?: ?string, running?: mixed, limit?: mixed}  $filters
     * @return array<string, mixed>
     *
     * @throws AgentRefusal|ReferenceException
     */
    public function list(int $accountId, array $filters): array
    {
        $limit = $this->limit($filters['limit'] ?? null);
        [$from, $until] = $this->range($filters);
        $task = $this->filled($filters['task'] ?? null) ? $this->resolver->task((string) $filters['task'], $accountId) : null;
        $client = $this->filled($filters['client'] ?? null) ? $this->resolver->client((string) $filters['client'], $accountId) : null;
        $running = filter_var($filters['running'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $query = TimeEntry::query()
            ->where('account_id', $accountId)
            ->when($task !== null, fn (Builder $q) => $q->where('task_id', $task->id))
            ->when($client !== null, fn (Builder $q) => $q->where('client_id', $client->id))
            ->when($from !== null, fn (Builder $q) => $q->where('start_time', '>=', $from->utc()))
            ->when($until !== null, fn (Builder $q) => $q->where('start_time', '<', $until->utc()))
            ->when($running, fn (Builder $q) => $q->whereNull('end_time'));

        $total = (clone $query)->count();
        $stoppedMinutes = (int) (clone $query)->whereNotNull('end_time')->sum('duration_minutes');
        $runningMinutes = (clone $query)->whereNull('end_time')->pluck('start_time')
            ->sum(fn ($start): int => TimeEntry::minutesBetween($start, now()));

        $entries = $query->with(TimeEntryRecord::WITH)
            ->orderByDesc('start_time')->orderByDesc('id')
            ->limit($limit)
            ->get();
        $freezes = $this->policy->loggedTimeFreezes($entries->pluck('client_id')->filter()->unique()->values()->all());

        return [
            'timezone' => LocalCalendar::timezone(),
            'from' => $from?->toDateString(),
            'to' => $until?->subDay()->toDateString(),
            'total' => $total,
            'total_minutes' => $stoppedMinutes + $runningMinutes,
            'entries' => $entries->map(fn (TimeEntry $entry): array => TimeEntryRecord::item($entry, $freezes[$entry->client_id] ?? null))->values()->all(),
        ];
    }

    /**
     * Local midnight of the first day and of the day after the last; either may be open.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function range(array $filters): array
    {
        $day = $this->filled($filters['day'] ?? null) ? (string) $filters['day'] : null;
        $from = $this->filled($filters['from'] ?? null) ? $this->day((string) $filters['from'], 'from') : null;
        $to = $this->filled($filters['to'] ?? null) ? $this->day((string) $filters['to'], 'to') : null;

        if ($day !== null) {
            if ($from !== null || $to !== null) {
                throw AgentRefusal::invalid('Pass day, or from and to, not both.');
            }
            $date = $this->day($day, 'day');

            return [$date, $date->addDay()];
        }
        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            throw AgentRefusal::invalid('from is after to.', ['field' => 'from']);
        }

        return [$from, $to?->addDay()];
    }

    private function day(string $value, string $field): CarbonImmutable
    {
        if (mb_strtolower(mb_trim($value)) === 'yesterday') {
            return LocalCalendar::today()->subDay();
        }

        return AgentDates::day($value, $field);
    }

    private function limit(mixed $limit): int
    {
        if ($limit === null || $limit === '') {
            return self::DEFAULT_LIMIT;
        }
        if (! is_numeric($limit) || (int) $limit < 1) {
            throw AgentRefusal::invalid('limit takes a whole number, 1 or more.');
        }

        return min((int) $limit, self::MAX_LIMIT);
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && mb_trim($value) !== '';
    }
}
