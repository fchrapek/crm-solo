<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\Clients\LoggedTimeFreeze;
use App\Services\Time\PeriodSettled;
use App\Services\Time\TimeEntryChanged;
use App\Services\Time\TimeEntryWriter;
use App\Support\LocalCalendar;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Manual time-entry CRUD: creates `manual` entries, edits and deletes any source.
 *
 * Conflict check: the picker dialog calls `running()` to discover any open
 * entry (`end_time IS NULL`) before letting the user submit. If one exists
 * the frontend surfaces a themed warning before opening the entry form so
 * the user doesn't accidentally double-log against a running timer.
 */
final class TimeEntriesController extends Controller
{
    public function running(): JsonResponse
    {
        $entries = TimeEntry::query()
            ->where('account_id', Auth::user()->account_id)
            ->whereNull('end_time')
            ->with(['task:id,name', 'project:id,name', 'client:id,name'])
            ->latest('start_time')
            ->get()
            ->map(fn (TimeEntry $e) => [
                'id' => $e->id,
                'description' => $e->description,
                'start_time' => $e->start_time?->toIso8601String(),
                'source' => $e->source,
                'task' => $e->task ? ['id' => $e->task->id, 'name' => $e->task->name] : null,
                'project' => $e->project ? ['id' => $e->project->id, 'name' => $e->project->name] : null,
                'client' => $e->client ? ['id' => $e->client->id, 'name' => $e->client->name] : null,
            ]);

        return response()->json(['entries' => $entries]);
    }

    public function store(Request $request, Task $task, TimeEntryWriter $writer): JsonResponse
    {
        $this->authorizeTask($task);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'start_time' => ['required', 'date'],
            'end_time' => ['nullable', 'date', 'after:start_time'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'billable' => ['boolean'],
        ]);

        $start = $this->instant($data['start_time']);
        // Either end_time OR duration_minutes is acceptable. If both provided,
        // end_time wins and we recompute minutes from it; if only duration is
        // given, derive end_time from start + duration so the row is well-
        // formed (closed entries always have both for downstream queries).
        if (! empty($data['end_time'])) {
            $end = $this->instant($data['end_time']);
            $minutes = TimeEntry::minutesBetween($start, $end);
        } elseif (isset($data['duration_minutes'])) {
            $minutes = (int) $data['duration_minutes'];
            $end = (clone $start)->addMinutes($minutes);
        } else {
            return response()->json([
                'message' => 'Either end_time or duration_minutes is required for a manual entry.',
            ], 422);
        }

        try {
            $entry = $writer->create([
                'account_id' => $task->project->account_id,
                'project_id' => $task->project_id,
                'client_id' => $task->project->client_id,
                'task_id' => $task->id,
                'source' => TimeEntry::SOURCE_MANUAL,
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? $task->name,
                'start_time' => $start,
                'end_time' => $end,
                'duration_minutes' => $minutes,
                'billable' => (bool) ($data['billable'] ?? true),
            ]);
        } catch (PeriodSettled $settled) {
            return $this->settled($settled);
        }

        return response()->json($this->serialize($entry->fresh()), 201);
    }

    public function update(Request $request, TimeEntry $timeEntry, TimeEntryWriter $writer): JsonResponse
    {
        $this->authorizeEntry($timeEntry);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'task_id' => ['sometimes', 'nullable', 'integer'],
            'start_time' => ['sometimes', 'date'],
            'end_time' => ['nullable', 'date', 'after:start_time'],
            // No upper cap: a session can run over several days.
            'duration_minutes' => ['nullable', 'integer', 'min:0'],
            'billable' => ['boolean'],
        ]);

        $changes = array_intersect_key($data, array_flip(['title', 'description']));
        if (array_key_exists('task_id', $data)) {
            // A task from another account is ignored, never connected.
            $task = $data['task_id'] === null ? null : Task::whereKey($data['task_id'])
                ->whereHas('project', fn ($q) => $q->where('account_id', $timeEntry->account_id))
                ->first();
            if ($data['task_id'] === null || $task !== null) {
                $changes['task'] = $task;
            }
        }
        if (isset($data['billable'])) {
            $changes['billable'] = (bool) $data['billable'];
        }
        if (isset($data['start_time'])) {
            $changes['start'] = $this->instant($data['start_time']);
        }
        if (array_key_exists('end_time', $data) && $data['end_time'] !== null) {
            $changes['end'] = $this->instant($data['end_time']);
        } elseif (isset($data['duration_minutes'])) {
            $changes['minutes'] = (int) $data['duration_minutes'];
        }

        try {
            $written = $writer->update($timeEntry, $changes);
        } catch (PeriodSettled $settled) {
            return $this->settled($settled);
        } catch (TimeEntryChanged) {
            return response()->json(['message' => __('This entry changed while you were editing it. Reload and try again.')], 409);
        }

        return response()->json($this->serialize($written));
    }

    public function destroy(TimeEntry $timeEntry, TimeEntryWriter $writer): JsonResponse
    {
        $this->authorizeEntry($timeEntry);

        try {
            $writer->delete($timeEntry);
        } catch (PeriodSettled $settled) {
            return $this->settled($settled);
        } catch (TimeEntryChanged) {
            return response()->json(['message' => __('This entry changed while you were editing it. Reload and try again.')], 409);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Open a manual TimeEntry against this task with `end_time=null` — i.e.
     * a running stopwatch. Distinct from `store()`: that one logs past work
     * (always closed), this one starts a live timer the user stops later.
     *
     * Runs alongside other running timers by design; conflict warning
     * for double-tracking is surfaced by the UI before this call (via the
     * existing `/time-entries/running` endpoint).
     *
     * `request_id` names one click: a repeat of it returns the timer that click
     * opened (200) instead of opening another, while a new click on the same
     * task still starts a second timer.
     */
    public function start(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTask($task);

        $data = $request->validate(['request_id' => ['nullable', 'uuid']]);

        $entry = TimeEntry::startFor($task, requestId: $data['request_id'] ?? null);

        // A replay returns the timer that request opened, stopped or not; the same id on another task is a client bug.
        if (! $entry->wasRecentlyCreated && $entry->task_id !== $task->id) {
            return response()->json(['message' => 'This request id already started a timer on another task.'], 409);
        }

        return response()->json($this->serialize($entry), $entry->wasRecentlyCreated ? 201 : 200);
    }

    /** Works for any source. */
    public function stop(TimeEntry $timeEntry): JsonResponse
    {
        $this->authorizeEntry($timeEntry);

        if (! $timeEntry->stopNow()) {
            return response()->json(['message' => 'Time entry is already stopped.'], 422);
        }

        return response()->json($this->serialize($timeEntry->fresh()));
    }

    private function settled(PeriodSettled $settled): JsonResponse
    {
        $month = LocalCalendar::monthName($settled->month);

        return response()->json(['message' => $settled->reason === LoggedTimeFreeze::CLOSED
            ? __(':Month is closed. Reopen it to change its time.', ['month' => $month])
            : __('The report for :month is finalized or sent. Reopen it to change its time.', ['month' => $month]),
        ], 422);
    }

    private function authorizeTask(Task $task): void
    {
        $task->loadMissing('project');
        if ($task->project?->account_id !== Auth::user()->account_id) {
            abort(403);
        }
    }

    private function authorizeEntry(TimeEntry $entry): void
    {
        if ($entry->account_id !== Auth::user()->account_id) {
            abort(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(TimeEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'description' => $entry->description,
            'start_time' => $entry->start_time?->toIso8601String(),
            'end_time' => $entry->end_time?->toIso8601String(),
            'duration_minutes' => $entry->duration_minutes,
            'billable' => (bool) $entry->billable,
            'source' => $entry->source,
        ];
    }

    /** An input instant in storage time: Eloquent saves a Carbon's wall clock, so an offset must be converted first. */
    private function instant(string $value): Carbon
    {
        return Carbon::parse($value)->setTimezone(config('app.timezone'));
    }
}
