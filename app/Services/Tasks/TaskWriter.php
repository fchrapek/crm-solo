<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Models\Project;
use App\Models\Task;
use App\Services\Integrations\Trello\TrelloListMapper;
use Illuminate\Support\Facades\DB;

/**
 * The one way to create a task and change it outside a tick: the task form,
 * the board, `tasks:create`, `crm task-create` / `task-edit` and MCP
 * `task_create` / `task_edit`. Lane moves into or out of Done go through
 * TaskCompletion. A Trello card keeps everything its board owns.
 */
final class TaskWriter
{
    public const array LISTS = [
        TrelloListMapper::LANE_BACKLOG,
        TrelloListMapper::LANE_TODO,
        TrelloListMapper::LANE_DOING,
        TrelloListMapper::LANE_TESTING,
        TrelloListMapper::LANE_DONE,
    ];

    /** The changes a Trello card refuses; `archived` stands for archived_at. */
    public const array TRELLO_OWNED = ['name', 'description', 'due_date', 'list_name', 'project_id', 'archived'];

    public function __construct(private readonly TaskCompletion $completion) {}

    /**
     * A task born on Done is finished through the same transition as a tick.
     *
     * @param  array<string, mixed>  $attributes  name and any of description, list_name, due_date, priority, parent_task_id, is_reportable, source
     */
    public function create(Project $project, array $attributes): Task
    {
        return DB::transaction(function () use ($project, $attributes): Task {
            $task = Task::create([
                'source' => Task::SOURCE_MANUAL,
                'is_reviewed' => true,
                ...$attributes,
                'project_id' => $project->id,
                'list_name' => $attributes['list_name'] ?? TrelloListMapper::LANE_TODO,
                'is_reportable' => (bool) ($attributes['is_reportable'] ?? false),
            ]);

            if ($task->list_name === TrelloListMapper::LANE_DONE) {
                $this->completion->finish($task);
            }

            return $task;
        });
    }

    /**
     * Changes only the keys given. A list change is a lane move, so into Done
     * finishes the task and out of Done reopens it.
     *
     * @param  array<string, mixed>  $changes  any of name, description, due_date, priority, parent_task_id, is_reportable, project_id, list_name
     *
     * @throws TrelloOwnedFields
     */
    public function update(Task $task, array $changes): Task
    {
        $this->guardCard($task, array_keys($changes));

        $list = $changes['list_name'] ?? null;
        unset($changes['list_name']);

        DB::transaction(function () use ($task, $changes, $list): void {
            if ($changes !== []) {
                $task->update($changes);
            }
            if ($list !== null) {
                $this->completion->moveToLane($task, $list);
            }
        });

        return $task;
    }

    /**
     * @throws TrelloOwnedFields
     */
    public function setArchived(Task $task, bool $archived): void
    {
        $this->guardCard($task, ['archived']);

        $task->update(['archived_at' => $archived ? now() : null]);
    }

    /**
     * @throws TrelloOwnedFields
     */
    public function moveToList(Task $task, string $list): void
    {
        $this->guardCard($task, ['list_name']);

        $this->completion->moveToLane($task, $list);
    }

    /**
     * @param  list<string>  $fields
     *
     * @throws TrelloOwnedFields
     */
    public function guardCard(Task $task, array $fields): void
    {
        $owned = array_values(array_intersect(self::TRELLO_OWNED, $fields));
        if ($task->hasTrelloCard() && $owned !== []) {
            throw new TrelloOwnedFields((int) $task->id, $owned);
        }
    }
}
