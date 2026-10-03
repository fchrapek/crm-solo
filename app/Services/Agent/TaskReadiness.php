<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Task;
use App\Models\TaskCardDetails;
use App\Services\Tasks\CardDescription;

/**
 * Whether a task carries enough for an agent to start without asking: a
 * description that says something, a target (where to work, or a link) and
 * a done condition. Computed on read, never stored, and never a gate.
 */
final class TaskReadiness
{
    public const string MISSING_DESCRIPTION = 'description';

    public const string MISSING_TARGET = 'target';

    public const string MISSING_DONE_CONDITION = 'done_condition';

    /**
     * @return array{ready: bool, missing: list<string>}
     */
    public static function evaluate(string $normalizedDescription, bool $hasTarget, bool $hasDoneCondition): array
    {
        $missing = array_values(array_filter([
            CardDescription::isSubstantive($normalizedDescription) ? null : self::MISSING_DESCRIPTION,
            $hasTarget ? null : self::MISSING_TARGET,
            $hasDoneCondition ? null : self::MISSING_DONE_CONDITION,
        ]));

        return ['ready' => $missing === [], 'missing' => $missing];
    }

    /** The relations a task list eager-loads so forTask() reads no JSON and makes no query. */
    public static function listRelations(string $prefix = ''): array
    {
        return [
            $prefix.'brief:id,task_id,location,done_when',
            $prefix.'cardDetails:'.implode(',', TaskCardDetails::READINESS_COLUMNS),
        ];
    }

    /**
     * Reads only the task's columns and already loaded data, so a list can
     * call it per row without a query or a Trello fetch. A list eager-loads
     * the brief and TaskCardDetails::READINESS_COLUMNS, never the JSON.
     *
     * @return array{ready: bool, missing: list<string>}
     */
    public static function forTask(Task $task): array
    {
        $description = CardDescription::normalize($task->description);

        return self::evaluate(
            $description,
            $task->brief?->value('where') !== null
                || (bool) $task->cardDetails?->has_link_attachment
                || CardDescription::links($description) !== [],
            $task->brief?->value('done_when') !== null
                || (int) $task->cardDetails?->checklist_items > 0,
        );
    }
}
