<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Clockify\PushTimeEntryToClockify;
use App\Actions\Clockify\UpdateTimeEntryInClockify;
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

        $start = Carbon::parse($data['start_time']);
        // Either end_time OR duration_minutes is acceptable. If both provided,
        // end_time wins and we recompute minutes from it; if only duration is
        // given, derive end_time from start + duration so the row is well-
        // formed (closed entries always have both for downstream queries).
        if (! empty($data['end_time'])) {
            $end = Carbon::parse($data['end_time']);
            $minutes = max(0, (int) ceil($start->diffInSeconds($end) / 60));
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

        // Best-effort Clockify push — Clockify integration not configured →
        // silently no-op; failure logs but doesn't reject the response.
        (new PushTimeEntryToClockify)($entry->load('project.client'));

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
                $timeEntry->start_time = Carbon::parse($data['start_time']);
            }
            if (array_key_exists('end_time', $data) && $data['end_time'] !== null) {
                $end = Carbon::parse($data['end_time']);
                $timeEntry->end_time = $end;
                $timeEntry->duration_minutes = max(0, (int) ceil(
                    $timeEntry->start_time->diffInSeconds($end) / 60
                ));
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

        // Push the update to Clockify best-effort. Only fires if the entry was
        // already mirrored (has clockify_entry_id) — otherwise PushTimeEntryToClockify
        // handles the create flow. Network failures log + swallow so the local
        // edit always succeeds.
        if ($timeEntry->clockify_entry_id !== null && $timeEntry->clockify_entry_id !== '') {
            (new UpdateTimeEntryInClockify)($timeEntry->fresh());
        }

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
     */
    public function start(Task $task): JsonResponse
    {
        $this->authorizeTask($task);

        $entry = TimeEntry::create([
            'account_id' => $task->project->account_id,
            'project_id' => $task->project_id,
            'client_id' => $task->project->client_id,
            'task_id' => $task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'description' => $task->name,
            'start_time' => now(),
            'end_time' => null,
            'duration_minutes' => 0,
            'billable' => true,
        ]);

        return response()->json($this->serialize($entry), 201);
    }

    /**
     * Works for any source: the manual stopwatch, or — though no UI offers
     * it — closing a terminal_session entry early.
     */
    public function stop(TimeEntry $timeEntry): JsonResponse
    {
        $this->authorizeEntry($timeEntry);

        if ($timeEntry->end_time !== null) {
            return response()->json(['message' => 'Time entry is already stopped.'], 422);
        }

        $end = now();
        $minutes = max(0, (int) ceil($timeEntry->start_time->diffInSeconds($end) / 60));
        $timeEntry->update([
            'end_time' => $end,
            'duration_minutes' => $minutes,
        ]);

        // Same best-effort push path used by manual `store()` and the launcher's
        // session-close hook.
        (new PushTimeEntryToClockify)($timeEntry->fresh()->load('project.client'));

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
}
