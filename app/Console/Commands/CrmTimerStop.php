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
 * Agent verb: stop a running timer. With one open timer no argument is
 * needed; with several, the id (or a task-name fragment) picks one.
 * Closing sets end_time + duration, the same closing contract the
 * terminal-session launcher uses.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmTimerStop extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:timer-stop {entry? : Entry id or task-name fragment; omit when only one timer runs} {--desc= : Replace the description on close} {--json : Machine-readable output}';

    protected $description = 'Stop a running timer (entry id / task name; omit if only one is open)';

    public function handle(CrmEntityResolver $resolver, TimerService $timers): int
    {
        $needle = $this->argument('entry');

        try {
            $entry = $resolver->openTimeEntry($this->actingIdentity()->account->id, $needle === null ? null : (string) $needle);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        }

        $entry = $timers->stop($entry, $this->option('desc') ? (string) $this->option('desc') : null);
        $minutes = $entry->duration_minutes;

        if ($this->option('json')) {
            $this->raw($this->encodeJson($timers->stopPayload($entry)));

            return self::SUCCESS;
        }

        $this->raw("Timer stopped: #{$entry->id} - {$minutes} min");
        $this->fenced(['Task: '.$this->literal($entry->task?->name ?? 'no task').' ['.$this->literal($entry->client?->name).']']);

        return self::SUCCESS;
    }
}
