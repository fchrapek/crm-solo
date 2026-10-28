<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\TimeEntry;
use App\Services\Clients\LoggedTimeFreeze;
use App\Support\LocalCalendar;
use Carbon\CarbonInterface;

/**
 * One time entry as every agent surface shows it: local wall-clock times, the
 * task, client and project it counts on, and whether its period still takes
 * an agent's change.
 */
final class TimeEntryRecord
{
    public const string FORMAT = 'Y-m-d H:i';

    /** Relations an item reads. */
    public const array WITH = ['task.project', 'project:id,name,trello_board_id', 'client:id,name'];

    /**
     * @return array<string, mixed>
     */
    public static function item(TimeEntry $entry, ?LoggedTimeFreeze $freeze): array
    {
        $running = $entry->end_time === null;
        $settled = $freeze?->covering(self::day($entry->start_time));

        return [
            'id' => (int) $entry->id,
            'start' => self::local($entry->start_time),
            'end' => $running ? null : self::local($entry->end_time),
            'minutes' => $running ? TimeEntry::minutesBetween($entry->start_time, now()) : (int) $entry->duration_minutes,
            'running' => $running,
            'task_id' => $entry->task_id,
            'task' => $entry->task?->name,
            'client_id' => $entry->client_id,
            'client' => $entry->client?->name,
            'project_id' => $entry->project_id,
            'project' => $entry->project?->name,
            'title' => $entry->title,
            'description' => $entry->description,
            'billable' => (bool) $entry->billable,
            'source' => $entry->source,
            'editable' => $settled === null,
            'locked' => $settled !== null ? self::lockedReason($settled) : null,
            'untrusted' => self::untrusted($entry),
        ];
    }

    /** The local calendar day an entry counts on: the day it started, as reports count it. */
    public static function day(CarbonInterface $instant): string
    {
        return $instant->copy()->setTimezone(LocalCalendar::timezone())->toDateString();
    }

    public static function local(CarbonInterface $instant): string
    {
        return $instant->copy()->setTimezone(LocalCalendar::timezone())->format(self::FORMAT);
    }

    /**
     * @param  array{reason: string, month: string}  $settled
     */
    public static function lockedReason(array $settled): string
    {
        return $settled['reason'] === LoggedTimeFreeze::CLOSED
            ? "{$settled['month']} is closed (Zamknij miesiąc): reopen it in the app to change its time."
            : "The report for {$settled['month']} is finalized or sent: reopen it in the app to change its time.";
    }

    /**
     * A card's or an email's task name and a board's name were written
     * outside the CRM. The title and description may have been copied from
     * one, then kept when the entry moved, so they are always listed.
     *
     * @return list<string>
     */
    private static function untrusted(TimeEntry $entry): array
    {
        $keys = match (true) {
            $entry->task !== null && TaskRecord::isExternal($entry->task) => ['task', 'project'],
            $entry->project?->trello_board_id !== null => ['project'],
            default => [],
        };

        return [...$keys, 'title', 'description'];
    }
}
