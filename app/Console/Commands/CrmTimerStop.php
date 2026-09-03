<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Agent\AmbiguousReferenceException;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\TimerService;
use Illuminate\Console\Command;

/**
 * Agent verb: stop a running timer. With one open timer no argument is
 * needed; with several, the id (or a task-name fragment) picks one.
 * Closing sets end_time + duration and best-effort pushes to Clockify —
 * the same closing contract the terminal-session launcher uses.
 */
final class CrmTimerStop extends Command
{
    protected $signature = 'crm:timer-stop {entry? : Entry id or task-name fragment; omit when only one timer runs} {--desc= : Replace the description on close} {--json : Machine-readable output}';

    protected $description = 'Stop a running timer (entry id / task name; omit if only one is open)';

    public function handle(CrmEntityResolver $resolver, TimerService $timers): int
    {
        $needle = $this->argument('entry');

        try {
            $entry = $resolver->openTimeEntry($needle === null ? null : (string) $needle);
        } catch (ReferenceException $e) {
            $this->error($e->getMessage());
            if ($e instanceof AmbiguousReferenceException) {
                $this->line($e->candidateLines());
            }

            return self::FAILURE;
        }

        $entry = $timers->stop($entry, $this->option('desc') ? (string) $this->option('desc') : null);
        $minutes = $entry->duration_minutes;

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'entry_id' => $entry->id,
                'minutes' => $minutes,
                'task' => $entry->task?->name,
                'client' => $entry->client?->name,
            ], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Timer stopped: #{$entry->id} — {$minutes} min on ".($entry->task?->name ?? 'no task')." [{$entry->client?->name}]");

        return self::SUCCESS;
    }
}
