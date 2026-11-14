<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Task;

/** What an agent must hear about a task's time when it logs, times or finishes it: whether that time counts at all. */
final class TaskBillingNotes
{
    /** @return array{reportable: bool, warnings: list<string>} */
    public static function forTask(?Task $task): array
    {
        if ($task === null) {
            return ['reportable' => false, 'warnings' => ['This time has no task, so it does not count in the report or the hours bank.']];
        }

        return [
            'reportable' => (bool) $task->is_reportable,
            'warnings' => $task->is_reportable ? [] : [
                "Task {$task->id} is not reportable: its time does not count in the report or the hours bank (task-edit {$task->id} --reportable=yes).",
            ],
        ];
    }
}
