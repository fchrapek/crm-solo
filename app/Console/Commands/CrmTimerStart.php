<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Agent\AmbiguousReferenceException;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\TimerService;
use Illuminate\Console\Command;

/**
 * Agent verb: start a live timer on a task ("start timer for X" in plain
 * language → this). Opens a TimeEntry with no end_time — exactly what the
 * dashboard's dangling-timer check watches — and crm:timer-stop closes it.
 * Overlapping timers are legal (parallel agent sessions bill concurrently),
 * so an existing open timer is reported, never blocking.
 */
final class CrmTimerStart extends Command
{
    protected $signature = 'crm:timer-start {task : Task id or name fragment} {--desc= : Description on the entry} {--not-billable} {--json : Machine-readable output}';

    protected $description = 'Start a live timer on a task (close it with crm:timer-stop)';

    public function handle(CrmEntityResolver $resolver, TimerService $timers): int
    {
        try {
            $task = $resolver->task((string) $this->argument('task'));
        } catch (ReferenceException $e) {
            $this->error($e->getMessage());
            if ($e instanceof AmbiguousReferenceException) {
                $this->line($e->candidateLines());
            }

            return self::FAILURE;
        }

        $open = $timers->open();

        $entry = $timers->start(
            $task,
            $this->option('desc') ? (string) $this->option('desc') : null,
            ! $this->option('not-billable'),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'entry_id' => $entry->id,
                'task_id' => $task->id,
                'client' => $task->project?->client?->name,
                'started_at' => $entry->start_time?->toIso8601String(),
                'other_open_timers' => $open->map(fn ($e) => ['id' => $e->id, 'task' => $e->task?->name])->values(),
            ], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Timer started: entry #{$entry->id} on task #{$task->id} \"{$task->name}\"".($task->project?->client ? " [{$task->project->client->name}]" : ''));
        foreach ($open as $other) {
            $this->line("  note: timer #{$other->id} is also open (".($other->task?->name ?? 'no task').') — overlaps are allowed');
        }

        return self::SUCCESS;
    }
}
