<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Account;
use App\Models\Lead;
use App\Models\MonthCloseRun;
use App\Models\OutboundMessage;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Support\LocalCalendar;

/**
 * The cross-client morning glance for one account: what needs attention today. Mirrors the
 * dashboard's attention rule (priority high, or AI-high with no human override)
 * plus overdue tasks, every open month-close run (oldest period first), hot
 * leads still in play, text messages that failed, any running time entry,
 * and the plan for today and tomorrow.
 */
final class TodayDigest
{
    public function __construct(private readonly DayPlan $plan) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $accountId): array
    {
        // The due-date bound only narrows the query; Task::isOverdue decides, since a
        // CRM date and a card's instant are read differently.
        $dueBefore = max(LocalCalendar::todayDate(), LocalCalendar::startOfTodayUtc());
        $attention = Task::query()
            ->open()
            ->whereHas('project', fn ($q) => $q->where('account_id', $accountId))
            ->where(function ($q) use ($dueBefore): void {
                $q->where('priority', 'high')
                    ->orWhere(fn ($inner) => $inner->whereNull('priority')->where('ai_priority', 'high'))
                    ->orWhere(fn ($inner) => $inner->whereNotNull('due_date')->where('due_date', '<', $dueBefore));
            })
            ->with('project.client:id,name')
            ->orderBy('due_date')
            ->get()
            ->filter(fn (Task $task): bool => $task->priority === 'high'
                || ($task->priority === null && $task->ai_priority === 'high')
                || $task->isOverdue())
            ->take(30)
            ->values()
            // Readiness data only for the 30 kept, and only its two small columns.
            ->load(TaskReadiness::listRelations())
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'name' => $task->name,
                'client' => $task->project?->client?->name,
                'project' => $task->project?->name,
                'due' => $task->dueDay(),
                'overdue' => $task->isOverdue(),
                ...$task->completionState(),
                ...TaskRecord::summary($task),
                'untrusted' => TaskRecord::untrustedListKeys($task, ['name', 'project', 'card_lane', 'card_url']),
            ]);

        // Every outstanding close, oldest first: a close normally runs for the previous month.
        $monthClose = ! Account::query()->findOrFail($accountId)->hasFeature('billing') ? collect() : MonthCloseRun::query()
            ->where('status', 'open')
            ->whereHas('client', fn ($q) => $q->where('account_id', $accountId))
            ->with('client:id,name')
            ->orderBy('period')
            ->orderBy('id')
            ->get()
            ->map(fn ($run) => [
                'client' => $run->client?->name,
                'period' => $run->period,
                'type' => $run->close_type,
            ]);

        $hotLeads = Lead::query()
            ->where('account_id', $accountId)
            ->get()
            ->filter(fn (Lead $lead): bool => $lead->tier() === Lead::TIER_GOLD && ! $lead->isWon())
            ->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'name' => $lead->name,
                'brand' => $lead->pipeline,
                'stage' => $lead->stage,
                'routing' => $lead->tierRouting(),
                // A lead's name arrives from a form or an import.
                'untrusted' => ['name'],
            ])
            ->values();

        $running = TimeEntry::query()
            ->whereNull('end_time')
            ->where('account_id', $accountId)
            ->with('client:id,name', 'task:id,name,source,trello_card_id')
            ->get()
            ->map(fn ($entry) => [
                'id' => $entry->id,
                'client' => $entry->client?->name,
                'task' => $entry->task?->name,
                'started' => $entry->start_time?->toDateTimeString(),
                'untrusted' => $entry->task !== null && TaskRecord::isExternal($entry->task) ? ['task'] : [],
            ]);

        // A failed text waits for the owner until it is sent again or cancelled.
        $failedMessages = OutboundMessage::query()
            ->where('account_id', $accountId)
            ->where('status', OutboundMessage::FAILED)
            ->with('client:id,name', 'rule.template:id,name')
            ->orderByDesc('scheduled_for')
            ->limit(30)
            ->get()
            ->map(fn (OutboundMessage $message) => [
                'id' => $message->id,
                'client' => $message->client?->name,
                'template' => $message->rule?->template?->name,
                'error' => $message->error,
                'may_have_gone_out' => OutboundMessage::mayHaveGoneOut($message->error),
                'untrusted' => ['client', 'template', 'error'],
            ]);

        return [
            'attention_tasks' => $attention,
            'month_close_open' => $monthClose,
            'hot_leads' => $hotLeads,
            'failed_messages' => $failedMessages,
            'running_time_entries' => $running,
            'plan' => [
                'today' => $this->plan->read($accountId, LocalCalendar::today()),
                'tomorrow' => $this->plan->read($accountId, LocalCalendar::today()->addDay()),
            ],
        ];
    }
}
