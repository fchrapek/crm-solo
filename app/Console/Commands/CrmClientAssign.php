<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use App\Services\Clients\Fields\ClientFieldWriter;
use Illuminate\Console\Command;

/** Agent verb: the person responsible for a client, or nobody. */
#[AccountScope(AccountScope::ACTING)]
final class CrmClientAssign extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:client-assign
        {client : Client id or name fragment}
        {user : User id, email or name fragment, or "none"}
        {--json : Machine-readable output}';

    protected $description = 'Assign a client to a user of the account (or none)';

    public function handle(CrmEntityResolver $resolver, ClientFieldWriter $writer): int
    {
        $identity = $this->actingIdentity();
        $needle = (string) $this->argument('user');

        try {
            $client = $resolver->client((string) $this->argument('client'), $identity->account->id);
            $user = $needle === 'none' ? null : $resolver->user($needle, $identity->account->id);
            $changed = $writer->assign($client, $user, $identity->user);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson(['client_id' => $client->id, 'assignee_id' => $user?->id, 'changed' => $changed]));

            return self::SUCCESS;
        }

        $this->raw($this->literal($client->name).' -> '.($user !== null ? $this->literal($user->name) : 'nobody').($changed ? '' : ' (unchanged)').'.');

        return self::SUCCESS;
    }
}
