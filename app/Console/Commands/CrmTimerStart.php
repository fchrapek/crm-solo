<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
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
#[AccountScope(AccountScope::ACTING)]
final class CrmTimerStart extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:timer-start {task : Task id or name fragment} {--desc= : Description on the entry} {--not-billable} {--json : Machine-readable output}';

    protected $description = 'Start a live timer on a task (close it with crm:timer-stop)';

    public function handle(CrmEntityResolver $resolver, TimerService $timers): int
    {
        $accountId = $this->actingIdentity()->account->id;

        try {
            $task = $resolver->task((string) $this->argument('task'), $accountId, openOnly: true);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        }

        $open = $timers->open($accountId);

        $entry = $timers->start(
            $task,
            $this->option('desc') ? (string) $this->option('desc') : null,
            ! $this->option('not-billable'),
        );

        if ($this->option('json')) {
            $this->raw($this->encodeJson($timers->startPayload($entry, $task, $open)));

            return self::SUCCESS;
        }

        $this->raw("Timer started: entry #{$entry->id} on task #{$task->id}");
        $lines = ['Task: '.$this->literal($task->name).($task->project?->client ? ' ['.$this->literal($task->project->client->name).']' : '')];
        foreach ($open as $other) {
            $lines[] = "  also open: timer #{$other->id} (".$this->literal($other->task?->name ?? 'no task').'), overlaps are allowed';
        }
        $this->fenced($lines);

        return self::SUCCESS;
    }
}
