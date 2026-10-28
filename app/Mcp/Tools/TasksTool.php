<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\TaskList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List tasks: open ones by default, filtered by client, project, part of the name, or due before a day. Each item has id, name, client, project, list, due, done, archived and whether it is a Trello card; card text is listed under that item\'s "untrusted". "total" says how many matched before the limit.')]
final class TasksTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'tasks';

    public function handle(Request $request, TaskList $tasks): Response
    {
        $validated = $request->validate([
            'client' => ['nullable', 'string'],
            'project' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'search' => ['nullable', 'string'],
            'due_before' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer'],
        ]);

        try {
            return $this->payload($tasks->list($this->identity()->account->id, $validated));
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
        } catch (AgentRefusal $e) {
            return $this->refusalError($e);
        }
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client' => $schema->string()->description('Client id or name fragment.'),
            'project' => $schema->string()->description('Project id or name fragment.'),
            'status' => $schema->string()->enum(TaskList::STATUSES)->description('open (default), done or all.'),
            'search' => $schema->string()->description('Part of the task name.'),
            'due_before' => $schema->string()->description('Only tasks due strictly before this day: today, tomorrow or YYYY-MM-DD, local time.'),
            'limit' => $schema->integer()->description('At most this many, default 50, up to 200.'),
        ];
    }
}
