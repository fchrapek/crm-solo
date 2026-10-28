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
 * Agent verb: change a task the way the task page does. Only the options
 * passed change; a Trello card refuses what its board owns. No delete.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmTaskEdit extends Command
{
    use PrintsAgentLists;

    protected $signature = 'crm:task-edit {task : Task id or name fragment}
        {--name= : New name}
        {--desc= : New description; empty clears it}
        {--due= : Due day: today, tomorrow, YYYY-MM-DD (local), or empty or "none" to clear}
        {--list= : Backlog, To-Do, Doing, Testing or Done}
        {--project= : Move to this project (id or name fragment)}
        {--reportable= : yes or no: whether time on the task counts in reports and the hours bank}
        {--move-time : Move the logged time of the task onto its project too (skips settled periods)}
        {--archive : Archive the task}
        {--unarchive : Take the task out of the archive}
        {--reopen : Reopen a done task (To-Do, or --list)}
        {--json : Machine-readable output}';

    protected $description = 'Edit a task: name, description, due, list, project, reportable, archive, reopen';

    public function handle(TaskEditor $editor): int
    {
        $reportable = $this->option('reportable');
        if ($reportable !== null && ! in_array($reportable, ['yes', 'no'], true)) {
            return $this->refusal(AgentRefusal::invalid('--reportable takes yes or no.', ['field' => 'reportable']));
        }
        if ($this->option('archive') && $this->option('unarchive')) {
            return $this->refusal(AgentRefusal::invalid('Pass --archive or --unarchive, not both.'));
        }

        try {
            $result = $editor->edit($this->actingIdentity()->account->id, (string) $this->argument('task'), [
                'name' => $this->option('name'),
                'description' => $this->option('desc'),
                'due' => $this->option('due'),
                'list' => $this->option('list'),
                'project' => $this->option('project'),
                'reportable' => $reportable === null ? null : $reportable === 'yes',
                'archive' => match (true) {
                    (bool) $this->option('archive') => true,
                    (bool) $this->option('unarchive') => false,
                    default => null,
                },
                'reopen' => (bool) $this->option('reopen'),
                'move_time' => (bool) $this->option('move-time'),
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

        $this->raw($result['changed'] === []
            ? "Task #{$result['task']['id']}: nothing to move."
            : "Updated task #{$result['task']['id']}: ".implode(', ', $result['changed']).'.');
        if (($result['moved_time'] ?? []) !== []) {
            $this->raw('Moved time entries: #'.implode(', #', $result['moved_time']).'.');
        }
        foreach ($result['skipped_time'] ?? [] as $skip) {
            $this->raw("Kept time entry #{$skip['id']} ({$skip['day']}): {$skip['message']}");
        }
        $this->fenced([$this->taskLine($result['task'])]);

        return self::SUCCESS;
    }
}
