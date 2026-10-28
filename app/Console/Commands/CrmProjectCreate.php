<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ProjectIntake;
use App\Services\Agent\ReferenceException;
use Illuminate\Console\Command;

/**
 * Agent verb: add a private project under a client, through the project
 * form's path. A live project of the same name is refused; --force adds it anyway.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmProjectCreate extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:project-create
        {--client= : Client id or name fragment}
        {--name= : Project name}
        {--description= : Description}
        {--force : Create it even when the client has a project of that name}
        {--json : Machine-readable output}';

    protected $description = 'Add a project under a client';

    public function handle(ProjectIntake $intake): int
    {
        try {
            $result = $intake->create($this->actingIdentity()->account->id, [
                'client' => $this->option('client'),
                'name' => $this->option('name'),
                'description' => $this->option('description'),
                'force' => (bool) $this->option('force'),
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

        $project = $result['project'];
        $this->raw("Created project #{$project['id']} under client #{$project['client_id']}.");
        $this->fenced(["  #{$project['id']} [".$this->literal($project['client']).'] '.$this->literal($project['name'])]);

        return self::SUCCESS;
    }
}
