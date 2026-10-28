<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\PrintsAgentLists;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\TimeEntryEditor;
use Illuminate\Console\Command;

/**
 * Agent verb: correct one time entry the way the time edit dialog does. Only
 * the options passed change; a settled month refuses. No delete.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmTimeEdit extends Command
{
    use PrintsAgentLists;

    protected $signature = 'crm:time-edit {entry : Time entry id (crm time-entries lists them)}
        {--minutes= : New length; keeps the start and moves the end (with --end, moves the start)}
        {--start= : New start, local: YYYY-MM-DD HH:MM, or HH:MM on the entry\'s day}
        {--end= : New end, local: YYYY-MM-DD HH:MM, or HH:MM on the entry\'s day}
        {--task= : Move it to this task (id or name fragment); project and client follow}
        {--desc= : New description; empty clears it}
        {--title= : New title; empty clears it}
        {--billable : Mark it billable}
        {--not-billable : Mark it not billable}
        {--json : Machine-readable output}';

    protected $description = 'Edit a time entry: minutes, start, end, task, description, title, billable';

    public function handle(TimeEntryEditor $editor): int
    {
        if ($this->option('billable') && $this->option('not-billable')) {
            return $this->refusal(AgentRefusal::invalid('Pass --billable or --not-billable, not both.'));
        }

        try {
            $result = $editor->edit($this->actingIdentity()->account->id, (string) $this->argument('entry'), [
                'minutes' => $this->option('minutes'),
                'start' => $this->option('start'),
                'end' => $this->option('end'),
                'task' => $this->option('task'),
                'description' => $this->option('desc'),
                'title' => $this->option('title'),
                'billable' => match (true) {
                    (bool) $this->option('billable') => true,
                    (bool) $this->option('not-billable') => false,
                    default => null,
                },
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

        $this->raw("Time entry #{$result['entry']['id']}: ".($result['changed'] !== [] ? 'changed '.implode(', ', $result['changed']) : 'nothing changed').'.');
        $this->fenced([$this->timeEntryLine($result['entry'])]);

        return self::SUCCESS;
    }
}
