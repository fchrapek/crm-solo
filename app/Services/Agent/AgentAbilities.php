<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Console\Commands\ClientConfig;
use App\Console\Commands\CrmBrief;
use App\Console\Commands\CrmLeadCapture;
use App\Console\Commands\CrmLeadStage;
use App\Console\Commands\CrmNote;
use App\Console\Commands\CrmTask;
use App\Console\Commands\CrmTaskBrief;
use App\Console\Commands\CrmTaskDone;
use App\Console\Commands\CrmTimerStart;
use App\Console\Commands\CrmTimerStop;
use App\Console\Commands\CrmToday;
use App\Console\Commands\LogTimeCommand;
use App\Console\Commands\MonthCloseTick;
use InvalidArgumentException;

/**
 * What a token may do, and which ability every agent verb and MCP tool needs.
 * One table for both transports, so a verb and its tool can never disagree.
 * Every call needs `read`; a write needs its group as well.
 */
final class AgentAbilities
{
    public const string READ = 'read';

    public const string NOTES = 'write:notes';

    public const string TASKS = 'write:tasks';

    public const string TIME = 'write:time';

    public const string LEADS = 'write:leads';

    public const string MONTH_CLOSE = 'write:month-close';

    /** Shorthand at issue time for every write group; it is never stored. */
    public const string WRITE = 'write';

    public const array ALL = [self::READ, self::NOTES, self::TASKS, self::TIME, self::LEADS, self::MONTH_CLOSE];

    /**
     * Remote verb => [artisan command, write ability or null for read-only].
     * This list is the whole surface of the verb endpoint.
     */
    public const array VERBS = [
        'today' => ['crm:today', null],
        'brief' => ['crm:brief', null],
        'task' => ['crm:task', null],
        'client:config' => ['client:config', null],
        'task-brief' => ['crm:task-brief', self::TASKS],
        'task-done' => ['crm:task-done', self::TASKS],
        'note' => ['crm:note', self::NOTES],
        'timer-start' => ['crm:timer-start', self::TIME],
        'timer-stop' => ['crm:timer-stop', self::TIME],
        'time:log' => ['time:log', self::TIME],
        'lead-capture' => ['crm:lead-capture', self::LEADS],
        'lead-stage' => ['crm:lead-stage', self::LEADS],
        'month-close:tick' => ['month-close:tick', self::MONTH_CLOSE],
    ];

    /** Artisan name => the command class, so the endpoint builds only the command it runs. */
    public const array COMMANDS = [
        'crm:today' => CrmToday::class,
        'crm:brief' => CrmBrief::class,
        'crm:task' => CrmTask::class,
        'client:config' => ClientConfig::class,
        'crm:task-brief' => CrmTaskBrief::class,
        'crm:task-done' => CrmTaskDone::class,
        'crm:note' => CrmNote::class,
        'crm:timer-start' => CrmTimerStart::class,
        'crm:timer-stop' => CrmTimerStop::class,
        'time:log' => LogTimeCommand::class,
        'crm:lead-capture' => CrmLeadCapture::class,
        'crm:lead-stage' => CrmLeadStage::class,
        'month-close:tick' => MonthCloseTick::class,
    ];

    /** MCP tool name => write ability or null for read-only. */
    public const array TOOLS = [
        'today' => null,
        'client_brief' => null,
        'client_config' => null,
        'task' => null,
        'month_close_status' => null,
        'task_brief' => self::TASKS,
        'task_done' => self::TASKS,
        'add_note' => self::NOTES,
        'timer_start' => self::TIME,
        'timer_stop' => self::TIME,
        'log_time' => self::TIME,
        'lead_capture' => self::LEADS,
        'lead_stage' => self::LEADS,
        'month_close_tick' => self::MONTH_CLOSE,
    ];

    /** task-brief with none of these only prints the brief, so it is a read. */
    private const array TASK_BRIEF_WRITE_OPTIONS = ['--where', '--done-when', '--constraints', '--notes', '--confirm'];

    /**
     * Expands `write` and checks every name, so a typo fails at issue instead
     * of silently granting nothing.
     *
     * @param  list<string>  $requested
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public static function normalise(array $requested): array
    {
        $out = [];
        foreach ($requested as $ability) {
            $ability = mb_trim($ability);
            if ($ability === self::WRITE) {
                array_push($out, self::NOTES, self::TASKS, self::TIME, self::LEADS, self::MONTH_CLOSE);

                continue;
            }
            if (! in_array($ability, self::ALL, true)) {
                throw new InvalidArgumentException("Unknown ability \"{$ability}\". Valid: ".implode(', ', [...self::ALL, self::WRITE]).'.');
            }
            $out[] = $ability;
        }

        return array_values(array_intersect(self::ALL, array_unique($out)));
    }

    /**
     * @param  array<int, mixed>  $granted
     */
    public static function grants(array $granted, string $ability): bool
    {
        return in_array($ability, $granted, true);
    }

    /**
     * The abilities a remote verb call needs, or null for a verb the endpoint does not run.
     *
     * @param  list<string>  $args
     * @return list<string>|null
     */
    public static function forVerb(string $verb, array $args): ?array
    {
        if (! array_key_exists($verb, self::VERBS)) {
            return null;
        }

        $write = self::VERBS[$verb][1];
        if ($verb === 'task-brief' && ! self::passesAnyOption($args, self::TASK_BRIEF_WRITE_OPTIONS)) {
            $write = null;
        }

        return $write === null ? [self::READ] : [self::READ, $write];
    }

    /** Whether an artisan command is one of the agent verbs, whose writes are audited. */
    public static function isVerbCommand(string $name): bool
    {
        return array_key_exists($name, self::COMMANDS);
    }

    /**
     * The abilities an MCP tool needs; a tool missing from the table needs every ability, so a new one is closed until listed.
     *
     * @return list<string>
     */
    public static function forTool(string $tool): array
    {
        if (! array_key_exists($tool, self::TOOLS)) {
            return self::ALL;
        }

        $write = self::TOOLS[$tool];

        return $write === null ? [self::READ] : [self::READ, $write];
    }

    /**
     * @param  list<string>  $args
     * @param  list<string>  $options
     */
    private static function passesAnyOption(array $args, array $options): bool
    {
        foreach ($args as $arg) {
            if ($arg === '--') {
                return false;
            }
            foreach ($options as $option) {
                if ($arg === $option || str_starts_with($arg, $option.'=')) {
                    return true;
                }
            }
        }

        return false;
    }
}
