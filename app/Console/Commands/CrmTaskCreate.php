<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\PrintsAgentLists;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\TaskEditor;
use Illuminate\Console\Command;

/**
 * Agent verb: create a task on a project, or on a client's general project,
 * through the task form's path.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmTaskCreate extends Command
{
    use PrintsAgentLists;

    protected $signature = 'crm:task-create
        {--name= : Task name}
        {--project= : Project id or name fragment}
        {--client= : Client id or name fragment: the task goes on its general project}
        {--list= : Backlog, To-Do (default), Doing, Testing or Done}
        {--due= : Due day: today, tomorrow or YYYY-MM-DD (local)}
        {--desc= : Description}
        {--reportable : Count time on the task in reports and the hours bank}
        {--json : Machine-readable output}';

    protected $description = 'Create a task on a project or a client (its general project)';

    public function handle(TaskEditor $editor): int
    {
        try {
            $result = $editor->create($this->actingIdentity()->account->id, [
                'name' => $this->option('name'),
                'project' => $this->option('project'),
                'client' => $this->option('client'),
                'list' => $this->option('list'),
                'due' => $this->option('due'),
                'description' => $this->option('desc'),
                'reportable' => (bool) $this->option('reportable'),
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

        $this->raw("Created task #{$result['task']['id']}.");
        $this->fenced([$this->taskLine($result['task'])]);

        return self::SUCCESS;
    }
}
