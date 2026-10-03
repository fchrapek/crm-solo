<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskSession;
use App\Models\TimeEntry;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Manual time-entry CRUD. Auto-tracked terminal-session entries are written
 * by `TerminalSessionLauncher` directly — this controller only handles user-
 * created `manual` entries plus edit/delete for any source.
 *
 * Conflict check: the picker dialog calls `running()` to discover any open
 * entry (`end_time IS NULL`) before letting the user submit. If one exists
 * the frontend surfaces a themed warning before opening the entry form so
 * the user doesn't accidentally double-log against an active agent session.
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

    public function store(Request $request, Task $task): JsonResponse
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

        $entry = TimeEntry::create([
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

        return response()->json($this->serialize($entry->fresh()), 201);
    }

    public function update(Request $request, TimeEntry $timeEntry): JsonResponse
    {
        $this->authorizeEntry($timeEntry);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'task_id' => ['sometimes', 'nullable', 'integer'],
            'start_time' => ['sometimes', 'date'],
            'end_time' => ['nullable', 'date', 'after:start_time'],
            // No upper cap on duration_minutes — overnight / multi-day sessions
            // (claude left running over a weekend) need to be editable to the
            // real span. The previous 1440-minute cap silently rejected those
            // with a confusing 422.
            'duration_minutes' => ['nullable', 'integer', 'min:0'],
            'billable' => ['boolean'],
        ]);

        DB::transaction(function () use ($data, $timeEntry): void {
            if (array_key_exists('title', $data)) {
                $timeEntry->title = $data['title'];
            }
            if (array_key_exists('description', $data)) {
                $timeEntry->description = $data['description'];
            }
            if (array_key_exists('task_id', $data)) {
                if ($data['task_id'] === null) {
                    $timeEntry->task_id = null;
                } else {
                    // Only connect a task that belongs to the same account;
                    // backfill project/client so the entry stays consistent.
                    $task = Task::whereKey($data['task_id'])
                        ->whereHas('project', fn ($q) => $q->where('account_id', $timeEntry->account_id))
                        ->with('project:id,client_id')
                        ->first();
                    if ($task !== null) {
                        $timeEntry->task_id = $task->id;
                        $timeEntry->project_id = $task->project_id;
                        if ($task->project?->client_id !== null) {
                            $timeEntry->client_id = $task->project->client_id;
                        }
                    }
                }
            }
            if (isset($data['billable'])) {
                $timeEntry->billable = (bool) $data['billable'];
            }
            if (isset($data['start_time'])) {
                $timeEntry->start_time = $this->instant($data['start_time']);
            }
            if (array_key_exists('end_time', $data) && $data['end_time'] !== null) {
                $end = $this->instant($data['end_time']);
                $timeEntry->end_time = $end;
                $timeEntry->duration_minutes = TimeEntry::minutesBetween($timeEntry->start_time, $end);
            } elseif (isset($data['duration_minutes'])) {
                $minutes = (int) $data['duration_minutes'];
                $timeEntry->duration_minutes = $minutes;
                $timeEntry->end_time = (clone $timeEntry->start_time)->addMinutes($minutes);
            }

            $timeEntry->save();

            // Keep the linked TaskSession row in sync — the history list shows
            // its started_at/ended_at, so editing the time should also correct
            // the row the user sees. Only TimeEntries from terminal sessions
            // have a TaskSession; manual entries no-op here.
            $session = TaskSession::query()
                ->where('time_entry_id', $timeEntry->id)
                ->first();
            if ($session !== null) {
                $patch = ['started_at' => $timeEntry->start_time];
                if ($timeEntry->end_time !== null) {
                    $patch['ended_at'] = $timeEntry->end_time;
                }
                $session->update($patch);
            }
        });

        return response()->json($this->serialize($timeEntry));
    }

    public function destroy(TimeEntry $timeEntry): JsonResponse
    {
        $this->authorizeEntry($timeEntry);
        $timeEntry->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Open a manual TimeEntry against this task with `end_time=null` — i.e.
     * a running stopwatch. Distinct from `store()`: that one logs past work
     * (always closed), this one starts a live timer the user stops later.
     *
     * Runs alongside terminal-session entries by design; conflict warning
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

    /**
     * Works for any source: the manual stopwatch, or — though no UI offers
     * it — closing a terminal_session entry early.
     */
    public function stop(TimeEntry $timeEntry): JsonResponse
    {
        $this->authorizeEntry($timeEntry);

        if (! $timeEntry->stopNow()) {
            return response()->json(['message' => 'Time entry is already stopped.'], 422);
        }

        return response()->json($this->serialize($timeEntry->fresh()));
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
