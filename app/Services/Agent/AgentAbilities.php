<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Console\Commands\ClientConfig;
use App\Console\Commands\CrmBrief;
use App\Console\Commands\CrmClientAssign;
use App\Console\Commands\CrmClientCreate;
use App\Console\Commands\CrmClientFieldDefine;
use App\Console\Commands\CrmClientFields;
use App\Console\Commands\CrmClientFieldSet;
use App\Console\Commands\CrmClients;
use App\Console\Commands\CrmClientSmsOptOut;
use App\Console\Commands\CrmLeadCapture;
use App\Console\Commands\CrmLeadStage;
use App\Console\Commands\CrmMessageCancel;
use App\Console\Commands\CrmMessagePreview;
use App\Console\Commands\CrmMessageResend;
use App\Console\Commands\CrmMessageRuleSet;
use App\Console\Commands\CrmMessages;
use App\Console\Commands\CrmMessageSending;
use App\Console\Commands\CrmMessageTemplateSet;
use App\Console\Commands\CrmMonthClose;
use App\Console\Commands\CrmMonthSettle;
use App\Console\Commands\CrmNote;
use App\Console\Commands\CrmPlan;
use App\Console\Commands\CrmProjectCreate;
use App\Console\Commands\CrmReport;
use App\Console\Commands\CrmReports;
use App\Console\Commands\CrmSearch;
use App\Console\Commands\CrmTask;
use App\Console\Commands\CrmTaskBrief;
use App\Console\Commands\CrmTaskCreate;
use App\Console\Commands\CrmTaskDone;
use App\Console\Commands\CrmTaskEdit;
use App\Console\Commands\CrmTasks;
use App\Console\Commands\CrmTimeEdit;
use App\Console\Commands\CrmTimeEntries;
use App\Console\Commands\CrmTimerStart;
use App\Console\Commands\CrmTimerStop;
use App\Console\Commands\CrmTimeSplit;
use App\Console\Commands\CrmToday;
use App\Console\Commands\CrmUnplan;
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

    public const string CLIENTS = 'write:clients';

    public const string MESSAGES = 'write:messages';

    /** Shorthand at issue time for every write group; it is never stored. */
    public const string WRITE = 'write';

    public const array ALL = [self::READ, self::NOTES, self::TASKS, self::TIME, self::LEADS, self::MONTH_CLOSE, self::CLIENTS, self::MESSAGES];

    /**
     * Remote verb => [artisan command, write ability or null for read-only].
     * This list is the whole surface of the verb endpoint.
     */
    public const array VERBS = [
        'today' => ['crm:today', null],
        'brief' => ['crm:brief', null],
        'task' => ['crm:task', null],
        'tasks' => ['crm:tasks', null],
        'clients' => ['crm:clients', null],
        'client-fields' => ['crm:client-fields', null],
        'search' => ['crm:search', null],
        'time-entries' => ['crm:time-entries', null],
        'client:config' => ['client:config', null],
        'task-brief' => ['crm:task-brief', self::TASKS],
        'task-done' => ['crm:task-done', self::TASKS],
        'task-create' => ['crm:task-create', self::TASKS],
        'task-edit' => ['crm:task-edit', self::TASKS],
        'plan' => ['crm:plan', self::TASKS],
        'unplan' => ['crm:unplan', self::TASKS],
        'client-create' => ['crm:client-create', self::CLIENTS],
        'client-field-define' => ['crm:client-field-define', self::CLIENTS],
        'client-field-set' => ['crm:client-field-set', self::CLIENTS],
        'client-assign' => ['crm:client-assign', self::CLIENTS],
        'client-sms-opt-out' => ['crm:client-sms-opt-out', self::MESSAGES],
        'messages' => ['crm:messages', null],
        'message-cancel' => ['crm:message-cancel', self::MESSAGES],
        'message-resend' => ['crm:message-resend', self::MESSAGES],
        'message-template-set' => ['crm:message-template-set', self::MESSAGES],
        'message-rule-set' => ['crm:message-rule-set', self::MESSAGES],
        'message-sending' => ['crm:message-sending', self::MESSAGES],
        'message-preview' => ['crm:message-preview', null],
        'project-create' => ['crm:project-create', self::CLIENTS],
        'note' => ['crm:note', self::NOTES],
        'timer-start' => ['crm:timer-start', self::TIME],
        'timer-stop' => ['crm:timer-stop', self::TIME],
        'time:log' => ['time:log', self::TIME],
        'time-edit' => ['crm:time-edit', self::TIME],
        'time-split' => ['crm:time-split', self::TIME],
        'lead-capture' => ['crm:lead-capture', self::LEADS],
        'lead-stage' => ['crm:lead-stage', self::LEADS],
        'month-close:tick' => ['month-close:tick', self::MONTH_CLOSE],
        'month-close' => ['crm:month-close', null],
        'report' => ['crm:report', null],
        'reports' => ['crm:reports', null],
        'month-settle' => ['crm:month-settle', null],
    ];

    /** Artisan name => the command class, so the endpoint builds only the command it runs. */
    public const array COMMANDS = [
        'crm:today' => CrmToday::class,
        'crm:brief' => CrmBrief::class,
        'crm:task' => CrmTask::class,
        'crm:tasks' => CrmTasks::class,
        'crm:clients' => CrmClients::class,
        'crm:search' => CrmSearch::class,
        'crm:time-entries' => CrmTimeEntries::class,
        'client:config' => ClientConfig::class,
        'crm:task-brief' => CrmTaskBrief::class,
        'crm:task-done' => CrmTaskDone::class,
        'crm:task-create' => CrmTaskCreate::class,
        'crm:task-edit' => CrmTaskEdit::class,
        'crm:plan' => CrmPlan::class,
        'crm:unplan' => CrmUnplan::class,
        'crm:client-create' => CrmClientCreate::class,
        'crm:client-fields' => CrmClientFields::class,
        'crm:client-field-define' => CrmClientFieldDefine::class,
        'crm:client-field-set' => CrmClientFieldSet::class,
        'crm:client-assign' => CrmClientAssign::class,
        'crm:client-sms-opt-out' => CrmClientSmsOptOut::class,
        'crm:messages' => CrmMessages::class,
        'crm:message-cancel' => CrmMessageCancel::class,
        'crm:message-resend' => CrmMessageResend::class,
        'crm:message-template-set' => CrmMessageTemplateSet::class,
        'crm:message-rule-set' => CrmMessageRuleSet::class,
        'crm:message-sending' => CrmMessageSending::class,
        'crm:message-preview' => CrmMessagePreview::class,
        'crm:project-create' => CrmProjectCreate::class,
        'crm:note' => CrmNote::class,
        'crm:timer-start' => CrmTimerStart::class,
        'crm:timer-stop' => CrmTimerStop::class,
        'time:log' => LogTimeCommand::class,
        'crm:time-edit' => CrmTimeEdit::class,
        'crm:time-split' => CrmTimeSplit::class,
        'crm:lead-capture' => CrmLeadCapture::class,
        'crm:lead-stage' => CrmLeadStage::class,
        'month-close:tick' => MonthCloseTick::class,
        'crm:month-close' => CrmMonthClose::class,
        'crm:report' => CrmReport::class,
        'crm:reports' => CrmReports::class,
        'crm:month-settle' => CrmMonthSettle::class,
    ];

    /** MCP tool name => write ability or null for read-only. */
    public const array TOOLS = [
        'today' => null,
        'client_brief' => null,
        'client_config' => null,
        'task' => null,
        'tasks' => null,
        'clients' => null,
        'search' => null,
        'time_entries' => null,
        'month_close_status' => null,
        'task_brief' => self::TASKS,
        'task_done' => self::TASKS,
        'task_create' => self::TASKS,
        'task_edit' => self::TASKS,
        'day_plan' => self::TASKS,
        'day_unplan' => self::TASKS,
        'client_create' => self::CLIENTS,
        'client_fields' => null,
        'client_field_define' => self::CLIENTS,
        'client_field_set' => self::CLIENTS,
        'client_assign' => self::CLIENTS,
        'client_sms_opt_out' => self::MESSAGES,
        'messages' => null,
        'message_cancel' => self::MESSAGES,
        'message_resend' => self::MESSAGES,
        'message_template_set' => self::MESSAGES,
        'message_rule_set' => self::MESSAGES,
        'message_sending' => self::MESSAGES,
        'message_preview' => null,
        'project_create' => self::CLIENTS,
        'add_note' => self::NOTES,
        'timer_start' => self::TIME,
        'timer_stop' => self::TIME,
        'log_time' => self::TIME,
        'time_edit' => self::TIME,
        'time_split' => self::TIME,
        'lead_capture' => self::LEADS,
        'lead_stage' => self::LEADS,
        'month_close_tick' => self::MONTH_CLOSE,
        'month_close_overview' => null,
        'report' => null,
        'reports' => null,
        'month_settlement_preview' => null,
    ];

    /**
     * MCP tool => the remote verb doing the same operation. Both transports
     * expose one set of operations; AgentSurfaceParityTest holds every tool and
     * every verb to a partner here.
     */
    public const array TOOL_VERBS = [
        'today' => 'today',
        'client_brief' => 'brief',
        'client_config' => 'client:config',
        'task' => 'task',
        'tasks' => 'tasks',
        'clients' => 'clients',
        'search' => 'search',
        'month_close_status' => 'month-close:tick',
        'task_brief' => 'task-brief',
        'task_done' => 'task-done',
        'task_create' => 'task-create',
        'task_edit' => 'task-edit',
        'day_plan' => 'plan',
        'day_unplan' => 'unplan',
        'add_note' => 'note',
        'timer_start' => 'timer-start',
        'timer_stop' => 'timer-stop',
        'log_time' => 'time:log',
        'time_entries' => 'time-entries',
        'time_edit' => 'time-edit',
        'time_split' => 'time-split',
        'lead_capture' => 'lead-capture',
        'lead_stage' => 'lead-stage',
        'month_close_tick' => 'month-close:tick',
        'client_create' => 'client-create',
        'client_fields' => 'client-fields',
        'client_field_define' => 'client-field-define',
        'client_field_set' => 'client-field-set',
        'client_assign' => 'client-assign',
        'client_sms_opt_out' => 'client-sms-opt-out',
        'messages' => 'messages',
        'message_cancel' => 'message-cancel',
        'message_resend' => 'message-resend',
        'message_template_set' => 'message-template-set',
        'message_rule_set' => 'message-rule-set',
        'message_sending' => 'message-sending',
        'message_preview' => 'message-preview',
        'project_create' => 'project-create',
        'month_close_overview' => 'month-close',
        'report' => 'report',
        'reports' => 'reports',
        'month_settlement_preview' => 'month-settle',
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
                array_push($out, ...array_diff(self::ALL, [self::READ]));

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
        if ($verb === 'plan' && self::onlyReadsThePlan($args)) {
            $write = null;
        }
        if ($verb === 'month-close:tick' && self::onlyListsTheChecklist($args)) {
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
     * `plan` with no task reads the day. Anything but `--json` and `--day=<value>`
     * might name a task, so it counts as the write.
     *
     * @param  list<string>  $args
     */
    private static function onlyReadsThePlan(array $args): bool
    {
        foreach ($args as $arg) {
            if ($arg !== '--json' && ! str_starts_with($arg, '--day=')) {
                return false;
            }
        }

        return true;
    }

    /**
     * `month-close:tick <client> list` only prints the checklist. Exactly two
     * arguments with `list` second, and no option but `--json` and
     * `--period=<value>`; any other form counts as the write.
     *
     * @param  list<string>  $args
     */
    private static function onlyListsTheChecklist(array $args): bool
    {
        $positional = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '-')) {
                if ($arg !== '--json' && ! str_starts_with($arg, '--period=')) {
                    return false;
                }

                continue;
            }
            $positional[] = $arg;
        }

        return count($positional) === 2 && $positional[1] === 'list';
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
