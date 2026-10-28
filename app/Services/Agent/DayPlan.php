<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Task;
use App\Services\Day\DayPlanner;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The day plan for `crm plan` / `unplan`, MCP `day_plan` / `day_unplan` and the
 * `plan` block of `today`: DayPlanner's picks and rules (today to the end of
 * next week in the display timezone, at most five, a closed day unchanged).
 */
final class DayPlan
{
    public function __construct(
        private readonly DayPlanner $planner,
        private readonly CrmEntityResolver $resolver,
    ) {}

    /**
     * From today to the end of next week, named (today, tomorrow) or as YYYY-MM-DD; today when nothing is given.
     *
     * @throws AgentRefusal
     */
    public function day(?string $value): CarbonImmutable
    {
        $date = $value === null || mb_trim($value) === '' ? LocalCalendar::today() : AgentDates::day($value, 'day');
        if (! $this->planner->isPlannable($date)) {
            throw AgentRefusal::invalid('A plan is for today up to the end of next week.', ['field' => 'day', 'last' => $this->planner->lastPlannableDay()->toDateString()]);
        }

        return $date;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(int $accountId, CarbonImmutable $date): array
    {
        $picks = $this->planner->picks($accountId, $date);
        $tasks = Task::query()
            ->with('project:id,trello_board_id')
            ->whereIn('id', $picks->pluck('task_id'))
            ->get(['id', 'project_id', 'source', 'trello_card_id'])
            ->keyBy('id');

        return [
            'date' => $date->toDateString(),
            'day' => match ($date->toDateString()) {
                LocalCalendar::today()->toDateString() => 'today',
                LocalCalendar::today()->addDay()->toDateString() => 'tomorrow',
                default => $date->toDateString(),
            },
            'closed' => $this->planner->isClosed($accountId, $date),
            'max_picks' => DayPlanner::MAX_PICKS,
            'picks' => $picks->map(fn (array $pick): array => [
                'task_id' => $pick['task_id'],
                'name' => $pick['name'],
                'client' => $pick['client'],
                'project' => $pick['project'],
                'done' => $pick['done'],
                'minutes' => $pick['minutes'],
                'untrusted' => $tasks->has($pick['task_id']) ? TaskRecord::untrustedListKeys($tasks[$pick['task_id']], ['name', 'project']) : [],
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AgentRefusal|ReferenceException
     */
    public function plan(int $accountId, string $task, CarbonImmutable $date): array
    {
        $picked = $this->resolver->task($task, $accountId, openOnly: true);

        try {
            $this->planner->pick($accountId, $date, (int) $picked->id);
        } catch (ValidationException $e) {
            throw AgentRefusal::invalid((string) collect($e->errors())->flatten()->first(), ['plan' => $this->read($accountId, $date)]);
        }

        return ['task_id' => (int) $picked->id, 'plan' => $this->read($accountId, $date)];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AgentRefusal|ReferenceException
     */
    public function unplan(int $accountId, string $task, CarbonImmutable $date): array
    {
        $pick = $this->resolver->dayPick($accountId, $date->toDateString(), $task);

        try {
            $this->planner->unpick($accountId, $pick);
        } catch (ValidationException $e) {
            throw AgentRefusal::invalid((string) collect($e->errors())->flatten()->first(), ['plan' => $this->read($accountId, $date)]);
        }

        return ['task_id' => (int) $pick->task_id, 'plan' => $this->read($accountId, $date)];
    }
}
