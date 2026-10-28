<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\PrintsAgentLists;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ClientIntake;
use Illuminate\Console\Command;

/**
 * Agent verb: add a client with the basics. A name close to an existing
 * client is refused with the matches listed; --force creates it anyway.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmClientCreate extends Command
{
    use PrintsAgentLists;

    protected $signature = 'crm:client-create
        {--name= : Client (company) name}
        {--type= : retainer or one_off}
        {--contact= : Contact person, "First Last"}
        {--email= : Email}
        {--phone= : Phone}
        {--nip= : NIP (Polish tax id)}
        {--note= : A note on the client}
        {--force : Create it even when a client with a close name exists}
        {--json : Machine-readable output}';

    protected $description = 'Add a client: name, type, contact, NIP, note (retainer terms stay in the app)';

    public function handle(ClientIntake $intake): int
    {
        try {
            $result = $intake->create($this->actingIdentity()->account->id, [
                'name' => $this->option('name'),
                'type' => $this->option('type'),
                'contact' => $this->option('contact'),
                'email' => $this->option('email'),
                'phone' => $this->option('phone'),
                'nip' => $this->option('nip'),
                'note' => $this->option('note'),
                'force' => (bool) $this->option('force'),
            ]);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($result));

            return self::SUCCESS;
        }

        $this->raw("Created client #{$result['client']['id']}".($result['contact_id'] !== null ? " with contact #{$result['contact_id']}" : '').'.');
        $this->fenced([$this->clientLine($result['client'])]);

        return self::SUCCESS;
    }
}
