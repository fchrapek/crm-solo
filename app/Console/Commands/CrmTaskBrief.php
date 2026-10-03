<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\PrintsTaskRecords;
use App\Models\TaskBrief;
use App\Services\Agent\AgentIdentityResolver;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\TaskBriefWriter;
use App\Services\Agent\TaskRecord;
use Illuminate\Console\Command;

/**
 * Agent verb: write the CRM brief on a task (where, done when, constraints,
 * notes). A write marks it drafted by the acting user; --confirm is the
 * owner confirming it. With no options it prints the current brief. Never
 * fetches from Trello.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmTaskBrief extends Command
{
    use PrintsTaskRecords;

    protected $signature = 'crm:task-brief {task : Task id or name fragment}
        {--where= : Where the work happens: a file, page, URL or component}
        {--done-when= : What has to be true for the task to be done}
        {--constraints= : What must not change, limits, preferences}
        {--notes= : Anything else the next reader needs}
        {--confirm : Confirm the brief as the owner}
        {--json : Machine-readable output}';

    protected $description = 'Write or print the CRM brief on a task (where, done when, constraints, notes)';

    public function handle(CrmEntityResolver $resolver, AgentIdentityResolver $identities, TaskBriefWriter $writer, TaskRecord $records): int
    {
        $identity = $identities->resolve();

        try {
            $task = $resolver->task((string) $this->argument('task'), $identity->account->id);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        }

        $changes = array_filter([
            'where' => $this->option('where'),
            'done_when' => $this->option('done-when'),
            'constraints' => $this->option('constraints'),
            'notes' => $this->option('notes'),
        ], fn (mixed $value): bool => $value !== null);

        if ($changes !== [] || $this->option('confirm')) {
            $writer->write($task, $changes, (bool) $this->option('confirm'), $identity, TaskBrief::VIA_CLI);
        }

        $payload = $records->briefPayload($task->fresh() ?? $task);

        if ($this->option('json')) {
            $this->raw($this->encodeJson($payload));

            return self::SUCCESS;
        }

        $this->printBrief($payload);

        return self::SUCCESS;
    }
}
