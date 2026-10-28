<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\PrintsAgentLists;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\TaskList;
use Illuminate\Console\Command;

/**
 * Agent verb: list tasks, open ones by default, filtered by client, project,
 * name and due day. Card text is fenced, and listed under `untrusted` in JSON.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmTasks extends Command
{
    use PrintsAgentLists;

    protected $signature = 'crm:tasks
        {--client= : Client id or name fragment}
        {--project= : Project id or name fragment}
        {--status=open : open, done or all}
        {--search= : Part of the task name}
        {--due-before= : Due strictly before this day: today, tomorrow or YYYY-MM-DD (local)}
        {--limit= : At most this many (default 50, up to 200)}
        {--json : Machine-readable output}';

    protected $description = 'List tasks: open by default, by client, project, name or due day';

    public function handle(TaskList $tasks): int
    {
        try {
            $result = $tasks->list($this->actingIdentity()->account->id, [
                'client' => $this->option('client'),
                'project' => $this->option('project'),
                'status' => $this->option('status'),
                'search' => $this->option('search'),
                'due_before' => $this->option('due-before'),
                'limit' => $this->option('limit'),
            ]);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($result));

            return self::SUCCESS;
        }

        $this->raw(ucfirst($result['status']).' tasks: '.count($result['tasks']).' of '.$result['total'].'.');
        $lines = array_map(fn (array $task): string => $this->taskLine($task), $result['tasks']);
        $this->fenced($lines !== [] ? $lines : ['  none']);

        return self::SUCCESS;
    }
}
