<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Task;
use App\Models\TaskBrief;
use App\Services\Humanizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Writes the CRM brief on a task. A change marks the brief drafted by the
 * acting user through the given transport and drops the confirmation of
 * every field it changed; confirming stamps every filled field as confirmed
 * by the acting user. Writes are free: nothing is gated on confirmation.
 */
final class TaskBriefWriter
{
    /**
     * @param  array<string, string|null>  $changes  record field => new text; an empty string clears the field
     */
    public function write(Task $task, array $changes, bool $confirm, AgentIdentity $identity, string $via): TaskBrief
    {
        $this->ensureRow($task);

        return DB::transaction(function () use ($task, $changes, $confirm, $identity, $via): TaskBrief {
            $brief = TaskBrief::query()->where('task_id', $task->id)->lockForUpdate()->firstOrFail();
            $confirmations = $brief->confirmations ?? [];
            $changed = false;

            foreach ($changes as $field => $value) {
                if (! array_key_exists($field, TaskBrief::FIELDS)) {
                    continue;
                }

                $new = $value === null || mb_trim($value) === '' ? null : Humanizer::clean(mb_trim($value));
                if ($new === $brief->value($field)) {
                    continue;
                }

                $brief->setAttribute(TaskBrief::FIELDS[$field], $new);
                unset($confirmations[$field]);
                $changed = true;
            }

            if ($changed) {
                $brief->fill([
                    'drafted_by_user_id' => $identity->user?->id,
                    'drafted_via' => $via,
                    'drafted_at' => now(),
                ]);
            }

            foreach (array_keys(TaskBrief::FIELDS) as $field) {
                if ($brief->value($field) === null) {
                    unset($confirmations[$field]);
                } elseif ($confirm && ! isset($confirmations[$field])) {
                    $confirmations[$field] = ['at' => now()->toIso8601String(), 'user_id' => $identity->user?->id];
                }
            }

            $brief->confirmations = $confirmations === [] ? null : $confirmations;
            $brief->save();

            return $brief;
        });
    }

    private function ensureRow(Task $task): void
    {
        try {
            TaskBrief::query()->firstOrCreate(['task_id' => $task->id]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent write created it first.
        }
    }
}
