<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\TimeEntry;
use App\Services\Clients\SettlementPolicy;
use App\Services\Time\PeriodSettled;
use App\Services\Time\TimeEntryChanged;
use App\Services\Time\TimeEntryWriter;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;

/**
 * Time entry edit and split for `crm time-edit` / `time-split` and MCP
 * `time_edit` / `time_split`: references resolved inside the account and
 * input checked, then the time edit dialog's own writer, which takes the
 * client lock and applies the settled-period rule. No delete.
 */
final class TimeEntryEditor
{
    public const string PERIOD_SETTLED = AgentRefusal::PERIOD_SETTLED;

    private const int TITLE_MAX = 255;

    private const int DESCRIPTION_MAX = 2000;

    private const int MAX_PARTS = 20;

    public function __construct(
        private readonly CrmEntityResolver $resolver,
        private readonly TimeEntryWriter $writer,
        private readonly SettlementPolicy $policy,
    ) {}

    /**
     * Only the keys given change. Time: `minutes` keeps the start and moves
     * the end, or with `end` moves the start; `start` and `end` alone keep
     * the other side.
     *
     * @param  array{minutes?: mixed, start?: ?string, end?: ?string, task?: ?string, description?: ?string, title?: ?string, billable?: ?bool}  $input
     * @return array<string, mixed>
     *
     * @throws AgentRefusal|ReferenceException
     */
    public function edit(int $accountId, string $reference, array $input): array
    {
        $entry = $this->resolver->timeEntry($reference, $accountId);
        $task = $this->filled($input['task'] ?? null) ? $this->resolver->task((string) $input['task'], $accountId) : null;

        $changes = [];
        if ($task !== null) {
            $changes['task'] = $task;
        }
        if (isset($input['description'])) {
            $changes['description'] = $this->text($input['description'], self::DESCRIPTION_MAX, 'description');
        }
        if (isset($input['title'])) {
            $changes['title'] = $this->text($input['title'], self::TITLE_MAX, 'title');
        }
        if (isset($input['billable'])) {
            $changes['billable'] = (bool) $input['billable'];
        }
        $minutes = $this->filled($input['minutes'] ?? null) ? $this->minutes($input['minutes'], 'minutes') : null;
        $timed = $minutes !== null || $this->filled($input['start'] ?? null) || $this->filled($input['end'] ?? null);

        if ($changes === [] && ! $timed) {
            throw AgentRefusal::invalid('Nothing to change: pass minutes, start, end, task, description, title, billable or not billable.');
        }

        if ($timed) {
            if ($entry->end_time === null) {
                throw AgentRefusal::invalid("Entry #{$entry->id} is running: stop it first (timer-stop {$entry->id}), then change its time. Task, description, title and billable change while it runs.", ['field' => 'minutes']);
            }
            [$changes['start'], $changes['end']] = $this->times($entry, $minutes, $input['start'] ?? null, $input['end'] ?? null);
        }

        $before = $this->snapshot($entry);
        $written = $this->write(fn (): TimeEntry => $this->writer->update($entry, $changes));

        return [
            'entry' => $this->items([$written])[0],
            'changed' => array_keys(array_diff_assoc($this->snapshot($written), $before)),
        ];
    }

    /**
     * Splits a stopped entry across tasks: parts laid end to end from its
     * start, the rest left on the entry unless $dropRest.
     *
     * @param  list<array{task?: mixed, minutes?: mixed, description?: mixed}>  $parts
     * @return array<string, mixed>
     *
     * @throws AgentRefusal|ReferenceException
     */
    public function split(int $accountId, string $reference, array $parts, bool $dropRest): array
    {
        $entry = $this->resolver->timeEntry($reference, $accountId);
        if ($parts === []) {
            throw AgentRefusal::invalid('Pass at least one part: a task and its minutes.', ['field' => 'parts']);
        }
        if (count($parts) > self::MAX_PARTS) {
            throw AgentRefusal::invalid('At most '.self::MAX_PARTS.' parts in one split.', ['field' => 'parts']);
        }

        $resolved = [];
        foreach ($parts as $index => $part) {
            $number = $index + 1;
            if (! is_array($part) || ! $this->filled($part['task'] ?? null)) {
                throw AgentRefusal::invalid("Part {$number} needs a task.", ['field' => 'parts']);
            }
            $resolved[] = [
                'task' => $this->resolver->task((string) $part['task'], $accountId),
                'minutes' => $this->minutes($part['minutes'] ?? null, "part {$number} minutes"),
                'description' => $this->filled($part['description'] ?? null) ? $this->text((string) $part['description'], self::DESCRIPTION_MAX, 'description') : null,
            ];
        }

        if ($entry->end_time === null) {
            throw AgentRefusal::invalid("Entry #{$entry->id} is running: stop it first (timer-stop {$entry->id}), then split it.");
        }
        $total = (int) $entry->duration_minutes;
        $span = TimeEntry::minutesBetween($entry->start_time, $entry->end_time);
        if ($total !== $span) {
            throw AgentRefusal::invalid("Entry #{$entry->id} records {$total} minutes but runs {$span} from start to end: set its time with time-edit first, then split it.", ['entry_minutes' => $total, 'span_minutes' => $span]);
        }
        $sum = array_sum(array_column($resolved, 'minutes'));
        if ($sum > $total) {
            throw AgentRefusal::invalid("The parts add up to {$sum} minutes, more than the {$total} of entry #{$entry->id}.", ['field' => 'parts', 'entry_minutes' => $total, 'parts_minutes' => $sum]);
        }
        $rest = $total - $sum;

        $rows = $this->write(fn (): array => $this->writer->split($entry, $resolved, $dropRest));

        return [
            'entries' => $this->items($rows),
            'remainder_minutes' => $dropRest ? 0 : $rest,
            'dropped_minutes' => $dropRest ? $rest : 0,
        ];
    }

    /**
     * The writer's refusals as the agent's.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    private function write(Closure $write): mixed
    {
        try {
            return $write();
        } catch (PeriodSettled $settled) {
            throw AgentRefusal::periodSettled($settled);
        } catch (TimeEntryChanged $changed) {
            throw AgentRefusal::invalid("Entry #{$changed->entryId} changed while it was being edited: read it again and retry.");
        }
    }

    /**
     * The new start and end as instants.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function times(TimeEntry $entry, ?int $minutes, ?string $start, ?string $end): array
    {
        $newStart = $this->filled($start) ? $this->instant((string) $start, $entry->start_time, 'start') : null;
        $newEnd = $this->filled($end) ? $this->instant((string) $end, $entry->end_time, 'end') : null;

        if ($minutes !== null && $newStart !== null && $newEnd !== null) {
            throw AgentRefusal::invalid('Pass two of minutes, start and end, not all three.', ['field' => 'minutes']);
        }
        if ($minutes !== null) {
            if ($newEnd !== null) {
                $newStart = $newEnd->subMinutes($minutes);
            } else {
                $newStart ??= CarbonImmutable::instance($entry->start_time);
                $newEnd = $newStart->addMinutes($minutes);
            }
        }
        $newStart ??= CarbonImmutable::instance($entry->start_time);
        $newEnd ??= CarbonImmutable::instance($entry->end_time);

        if ($newEnd->lessThanOrEqualTo($newStart)) {
            throw AgentRefusal::invalid('The end must be after the start.', ['field' => 'end']);
        }
        if ($newEnd->greaterThan(now())) {
            throw AgentRefusal::invalid('The end is in the future: an entry records work already done.', ['field' => 'end']);
        }

        return [$newStart, $newEnd];
    }

    /**
     * A local wall-clock time: YYYY-MM-DD HH:MM, or HH:MM on the local day the
     * entry's start or end falls on now.
     */
    private function instant(string $value, CarbonInterface $current, string $field): CarbonImmutable
    {
        $value = mb_trim($value);
        $zone = LocalCalendar::timezone();
        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            $value = $current->copy()->setTimezone($zone)->toDateString().' '.$value;
        }
        $value = str_replace('T', ' ', $value);

        $parsed = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d H:i', $value, $zone)
            : null;
        // A time skipped by the clock change reads back as another time and is refused.
        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d H:i') !== $value) {
            throw AgentRefusal::invalid("{$field} takes a local time as YYYY-MM-DD HH:MM, or HH:MM on the entry's day; {$value} is not one that exists here.", ['field' => $field]);
        }

        return $parsed->utc();
    }

    private function minutes(mixed $value, string $field): int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1)) {
            $minutes = (int) $value;
            if ($minutes >= 1) {
                return $minutes;
            }
        }

        throw AgentRefusal::invalid("{$field} takes a whole number of minutes, 1 or more.", ['field' => $field]);
    }

    private function text(string $value, int $max, string $field): ?string
    {
        $value = mb_trim($value);
        if (mb_strlen($value) > $max) {
            throw AgentRefusal::invalid("The {$field} is longer than {$max} characters.", ['field' => $field]);
        }

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(TimeEntry $entry): array
    {
        return [
            'task' => $entry->task_id,
            'start' => $entry->start_time?->toIso8601String(),
            'end' => $entry->end_time?->toIso8601String(),
            'minutes' => $entry->duration_minutes,
            'description' => $entry->description,
            'title' => $entry->title,
            'billable' => (bool) $entry->billable,
        ];
    }

    /**
     * @param  list<TimeEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function items(array $entries): array
    {
        $fresh = array_map(fn (TimeEntry $entry): TimeEntry => $entry->fresh(TimeEntryRecord::WITH) ?? $entry, $entries);
        $freezes = $this->policy->loggedTimeFreezes(array_values(array_unique(array_filter(array_map(fn (TimeEntry $entry): ?int => $entry->client_id !== null ? (int) $entry->client_id : null, $fresh)))));

        return array_map(fn (TimeEntry $entry): array => TimeEntryRecord::item($entry, $entry->client_id !== null ? $freezes[(int) $entry->client_id] : null), $fresh);
    }

    private function filled(mixed $value): bool
    {
        return (is_string($value) && mb_trim($value) !== '') || is_int($value);
    }
}
