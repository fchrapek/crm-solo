<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\Day\AddRequestConflict;
use App\Services\Day\DayPlanner;
use App\Services\Projects\ProjectChoices;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The day's add field: open tasks to pick by name, the projects a new one can go to, and the new task put on the day. */
final class DayTasksController extends Controller
{
    public function __construct(private readonly DayPlanner $planner) {}

    /** Matches for the typed text (two characters at least) in the plan's order; nothing typed gives the tasks worked on last. */
    public function tasks(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'min:2', 'max:500'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $date = $this->date($data['date'] ?? null);
        $text = mb_trim((string) ($data['q'] ?? ''));

        if ($text === '') {
            return response()->json(['query' => '', 'recent' => true, 'tasks' => $this->planner->recentTasks($this->accountId(), $date), 'total' => null]);
        }

        return response()->json(['query' => $text, 'recent' => false, ...$this->planner->matching($this->accountId(), $date, $text)]);
    }

    public function projects(ProjectChoices $choices): JsonResponse
    {
        return response()->json($choices->forAccount($this->accountId()));
    }

    /** Creates the task in the chosen project and picks it for the date, both or neither. */
    public function store(Request $request): JsonResponse
    {
        $account = $this->accountId();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:500'],
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->where('account_id', $account)->whereNull('archived_at')->where(fn ($query) => $query->where('is_inbox', false))],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'request_id' => ['nullable', 'uuid'],
        ]);
        $project = Project::query()->with('client')->findOrFail((int) $data['project_id']);
        if ($project->client_id !== null && $project->client === null) {
            throw ValidationException::withMessages(['project_id' => __('This project cannot take new tasks.')]);
        }

        try {
            $added = $this->planner->addNewTask($account, $this->date($data['date'] ?? null), $project, mb_trim($data['name']), $data['request_id'] ?? null);
        } catch (AddRequestConflict $conflict) {
            return response()->json(['message' => $conflict->getMessage()], 409);
        }

        return response()->json([
            'task' => [
                'task_id' => $added['task']->id,
                'name' => $added['task']->name,
                'client' => $project->client?->name,
                'project' => $project->name,
            ],
            'pick_id' => $added['pick']->id,
            'replayed' => $added['replayed'],
        ], $added['replayed'] ? 200 : 201);
    }

    private function date(?string $value): CarbonImmutable
    {
        return $value === null ? $this->planner->today() : CarbonImmutable::createFromFormat('!Y-m-d', $value, LocalCalendar::timezone());
    }

    private function accountId(): int
    {
        return (int) Auth::user()->account_id;
    }
}
