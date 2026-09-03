<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Agent\AmbiguousReferenceException;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\TimeLogger;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * One-liner manual time logging for the side-terminal workflow — mirrors
 * TimeEntriesController::store() so the CLI and the UI produce identical rows
 * (account-scoped, closed entry with consistent end_time + duration, optional
 * best-effort Clockify push). See docs/development/quick-db-ops.md.
 */
final class LogTimeCommand extends Command
{
    protected $signature = 'time:log
                            {minutes : Minutes to log (integer > 0)}
                            {--task= : Task ID or name — account/project/client derived from it}
                            {--client= : Client ID or name — logs to their General project (alt to --task)}
                            {--project= : Project ID or name — logs to the project, no task (alt to --task)}
                            {--desc= : Description (defaults to the task name, or "Ad-hoc work")}
                            {--title= : Short label shown in the Time list}
                            {--end= : End time in local (display) time; default now. start = end − minutes}
                            {--not-billable : Mark the entry non-billable (default: billable)}
                            {--no-push : Skip the best-effort Clockify push}
                            {--account= : Restrict target resolution to this account ID}';

    protected $description = 'Log a manual time entry from the CLI (mirrors the UI). Target with exactly one of --task / --client / --project.';

    public function handle(TimeLogger $logger): int
    {
        try {
            $result = $logger->log(
                minutes: (int) $this->argument('minutes'),
                task: $this->stringOption('task'),
                client: $this->stringOption('client'),
                project: $this->stringOption('project'),
                description: $this->stringOption('desc'),
                title: $this->stringOption('title'),
                end: $this->stringOption('end'),
                billable: ! $this->option('not-billable'),
                push: ! $this->option('no-push'),
                accountId: $this->intOption('account'),
            );
        } catch (ReferenceException $e) {
            $this->error($e->getMessage());
            if ($e instanceof AmbiguousReferenceException) {
                $this->line($e->candidateLines());
            }

            return self::FAILURE;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $hours = number_format($result['hours'], 2);

        $this->info("✓ #{$result['entry_id']} · {$result['minutes']} min ({$hours}h) · {$result['target']}");
        $this->line('  '.$result['start_local'].' → '.mb_substr((string) $result['end_local'], -5).' '.$result['timezone']
            .($result['billable'] ? ' · billable' : ' · non-billable'));

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return $value === null || $value === '' ? null : (string) $value;
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return $value === null || $value === '' ? null : (int) $value;
    }
}
