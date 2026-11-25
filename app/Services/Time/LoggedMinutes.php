<?php

declare(strict_types=1);

namespace App\Services\Time;

use App\Models\TimeEntry;

/** A task's logged time: the finished entries of the account, every day, billable or not. */
final class LoggedMinutes
{
    /**
     * @param  list<int>  $taskIds
     * @return array<int, int> minutes by task id; a task with nothing logged is left out
     */
    public static function byTask(int $accountId, array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        return TimeEntry::query()
            ->where('account_id', $accountId)
            ->whereIn('task_id', $taskIds)
            ->whereNotNull('end_time')
            ->groupBy('task_id')
            ->selectRaw('task_id, SUM(duration_minutes) as minutes')
            ->pluck('minutes', 'task_id')
            ->map(fn ($minutes): int => (int) $minutes)
            ->all();
    }
}
