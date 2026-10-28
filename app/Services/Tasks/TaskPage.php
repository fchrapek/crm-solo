<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Models\AgentAuditEvent;
use App\Models\AgentToken;
use App\Models\DayPick;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskBrief;
use App\Models\TaskLink;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Agent\AgentName;
use App\Services\Agent\TaskRecord;
use App\Support\LegalName;
use App\Support\LocalCalendar;
use Carbon\CarbonInterface;

/**
 * The props of the task page (Figma: Zadanie). Read only; every write the
 * page makes goes through the shared writers.
 */
final class TaskPage
{
    public function __construct(private readonly TaskActivity $activity) {}

    /**
     * @return array<string, mixed>
     */
    public function props(Task $task, User $viewer, ?int $activityLimit): array
    {
        $task->load([
            'project.client',
            'parentTask:id,name',
            'childTasks:id,parent_task_id,name,is_completed,finished_at',
            'attachments',
            'links',
            'brief',
            'cardDetails',
        ]);

        return [
            'task' => $this->task($task),
            'time' => $this->time($task),
            'brief' => $this->brief($task, $viewer),
            'card' => $this->card($task),
            'resources' => $this->resources($task),
            'activity' => $this->activity->forTask($task, $activityLimit, $viewer->id),
            'live' => $this->activity->live($task),
            'clock' => [
                'today' => LocalCalendar::today()->toDateString(),
                'now' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function task(Task $task): array
    {
        $client = $task->project?->client;

        return [
            'id' => $task->id,
            'name' => $task->name,
            'description' => $task->description,
            'description_markdown' => CardDescription::normalize($task->description),
            'list_name' => $task->list_name,
            'is_completed' => (bool) $task->is_completed,
            'finished_at' => $task->finished_at?->toIso8601String(),
            'is_done' => $task->isDone(),
            'can_unfinish' => $task->canUnfinish(),
            'is_archived' => $task->isArchived(),
            'picked_today' => DayPick::where('task_id', $task->id)->whereDate('date', LocalCalendar::today()->toDateString())->exists(),
            'due_date' => $task->due_date?->toIso8601String(),
            'due_day' => $task->dueDay(),
            'is_overdue' => $task->isOverdue(),
            'labels' => $task->labels,
            'priority' => $task->priority,
            'parent_task_id' => $task->parent_task_id,
            'parent_task' => $task->parentTask ? ['id' => $task->parentTask->id, 'name' => $task->parentTask->name] : null,
            'child_tasks' => $task->childTasks->map(fn (Task $child): array => [
                'id' => $child->id,
                'name' => $child->name,
                'is_completed' => (bool) $child->is_completed,
                'finished_at' => $child->finished_at?->toIso8601String(),
                'is_done' => $child->isDone(),
            ])->values(),
            'source' => $task->source,
            'has_trello_card' => $task->hasTrelloCard(),
            'trello_url' => $task->trello_url,
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
            'project' => $task->project ? [
                'id' => $task->project->id,
                'name' => $task->project->name,
                'trello_url' => $task->project->trello_url,
            ] : null,
            'client' => $client ? [
                'id' => $client->id,
                'name' => $client->name,
                'short_name' => LegalName::short($client->name),
            ] : null,
            'attachments' => $task->attachments->map(fn (TaskAttachment $a): array => [
                'id' => $a->id,
                'url' => route('task-attachments.show', $a->id),
                'original_name' => $a->original_name,
                'mime' => $a->mime,
                'size' => $a->size,
                'label' => $a->label,
            ])->values(),
        ];
    }

    /**
     * Minutes are whole and closed; a running timer's share is added on the
     * page as it runs, from its start and the start of the local day.
     *
     * @return array<string, mixed>
     */
    private function time(Task $task): array
    {
        [$dayStart, $dayEnd] = LocalCalendar::dayRange(LocalCalendar::today());
        $closed = TimeEntry::query()->where('task_id', $task->id)->whereNotNull('end_time');
        $running = TimeEntry::query()
            ->where('task_id', $task->id)
            ->whereNull('end_time')
            ->latest('start_time')
            ->get(['id', 'start_time', 'actor_via', 'actor_token_id']);
        // The owner's own timer: started from the web, never through an agent transport.
        $own = $running->first(fn (TimeEntry $entry): bool => $entry->actor_via === null && $entry->actor_token_id === null);

        return [
            'today_minutes' => (int) (clone $closed)->where('start_time', '>=', $dayStart)->where('start_time', '<', $dayEnd)->sum('duration_minutes'),
            'total_minutes' => (int) $closed->sum('duration_minutes'),
            'day_start' => $dayStart->toIso8601String(),
            'own_running' => $own !== null ? ['id' => $own->id, 'started_at' => $own->start_time?->toIso8601String()] : null,
            'running' => $running->map(fn (TimeEntry $entry): string => (string) $entry->start_time?->toIso8601String())->values(),
            // the newest running timer's start as the display timezone's clock, for the Czas label
            'running_since' => $running->first()?->start_time?->setTimezone(LocalCalendar::timezone())->format('H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function brief(Task $task, User $viewer): array
    {
        $brief = $task->brief;
        $fields = [];
        foreach (array_keys(TaskBrief::FIELDS) as $field) {
            $fields[$field] = $brief?->value($field);
        }
        $filled = count(array_filter($fields, fn (?string $value): bool => $value !== null));

        return [
            'fields' => $fields,
            'filled' => $filled,
            'last_change' => $brief !== null && $filled > 0 ? $this->briefChange($brief, $viewer) : null,
        ];
    }

    /**
     * Who last changed a field of the brief, from the brief's own drafted_*
     * columns (a confirmation never moves them). Through an agent transport
     * the name comes from the newest audit row that changed a field.
     *
     * @return array<string, mixed>|null
     */
    private function briefChange(TaskBrief $brief, User $viewer): ?array
    {
        $at = $brief->drafted_at ?? $brief->updated_at;
        if ($at === null) {
            return null;
        }

        if ($brief->drafted_via === null || $brief->drafted_via === TaskBrief::VIA_WEB) {
            $author = $brief->drafted_by_user_id;
            $actor = $author === null || $author === $viewer->id
                ? ['kind' => 'owner', 'name' => null]
                : ['kind' => 'person', 'name' => User::query()->whereKey($author)->first(['id', 'first_name', 'last_name'])?->name];

            return $this->stamp($actor, $at);
        }

        $columns = array_flip(array_values(TaskBrief::FIELDS));
        $tokenId = AgentAuditEvent::query()
            ->where('target_type', 'task_briefs')
            ->where('target_id', $brief->id)
            ->where('action', 'updated')
            ->latest('created_at')
            ->latest('id')
            ->limit(50)
            ->get(['id', 'token_id', 'changes'])
            ->first(fn (AgentAuditEvent $event): bool => array_intersect_key(is_array($event->changes['before'] ?? null) ? $event->changes['before'] : [], $columns) !== [])
            ?->token_id;
        $name = AgentName::fromToken($tokenId !== null ? AgentToken::query()->whereKey($tokenId)->value('name') : null);

        return $this->stamp(['kind' => 'agent', 'name' => $name], $at);
    }

    /**
     * @param  array{kind: string, name: string|null}  $actor
     * @return array<string, mixed>
     */
    private function stamp(array $actor, CarbonInterface $at): array
    {
        $local = $at->copy()->setTimezone(LocalCalendar::timezone());

        return ['actor' => $actor, 'at' => $at->toIso8601String(), 'day' => $local->toDateString(), 'time' => $local->format('H:i')];
    }

    /**
     * The card's cached checklists (Trello owns them; the CRM has no checklist of its own).
     *
     * @return array<string, mixed>|null
     */
    private function card(Task $task): ?array
    {
        if (! $task->hasTrelloCard()) {
            return null;
        }
        $details = $task->cardDetails;
        $checklists = collect($details?->checklists ?? [])
            ->filter(fn (mixed $list): bool => is_array($list))
            ->map(fn (array $list): array => [
                'name' => (string) ($list['name'] ?? ''),
                'items' => collect(is_array($list['items'] ?? null) ? $list['items'] : [])
                    ->filter(fn (mixed $item): bool => is_array($item))
                    ->map(fn (array $item): array => ['name' => (string) ($item['name'] ?? ''), 'done' => (bool) ($item['done'] ?? false)])
                    ->values()
                    ->all(),
            ])
            ->values();
        $items = $checklists->flatMap(fn (array $list): array => $list['items']);

        return [
            'fetched_at' => $details?->fetched_at?->toIso8601String(),
            'error' => $details?->fetch_error,
            'checklists' => $checklists->all(),
            'done' => $items->where('done', true)->count(),
            'total' => $items->count(),
        ];
    }

    /**
     * Zasoby: files, images that render as thumbnails, then links (the CRM's
     * own, then the card's link attachments, read only).
     *
     * @return array<string, mixed>
     */
    private function resources(Task $task): array
    {
        $files = $task->attachments->map(fn (TaskAttachment $a): array => [
            'id' => $a->id,
            'name' => $a->original_name,
            'url' => route('task-attachments.show', $a->id),
            'mime' => $a->mime,
            'size' => $a->size,
            'from' => $a->isFromTrello() ? 'trello' : 'upload',
            'is_image' => str_starts_with((string) $a->mime, 'image/') && ! str_contains((string) $a->mime, 'svg'),
        ]);

        $links = [
            ...$task->links->map(fn (TaskLink $link): array => ['id' => $link->id, 'url' => $link->url, 'label' => $link->label, 'from' => 'crm'])->all(),
            ...array_map(
                fn (array $link): array => ['id' => null, 'url' => $link['url'], 'label' => $link['text'], 'from' => 'trello'],
                TaskRecord::cardLinks(array_values($task->cardDetails?->card_attachments ?? [])),
            ),
        ];

        return [
            'files' => $files->where('is_image', false)->values()->all(),
            'images' => $files->where('is_image', true)->values()->all(),
            'links' => $links,
        ];
    }
}
