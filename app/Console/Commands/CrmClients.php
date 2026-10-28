<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\PrintsAgentLists;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ClientDirectory;
use Illuminate\Console\Command;

/**
 * Agent verb: list clients with their type, stage, billing mode and today's
 * retainer positions, by name, email, phone or NIP.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmClients extends Command
{
    use PrintsAgentLists;

    protected $signature = 'crm:clients
        {--search= : Part of the name, email, phone or NIP}
        {--stage= : active, paused or churned}
        {--type= : retainer, hourly, project or one_off}
        {--archived : Include archived clients}
        {--limit= : At most this many (default 100, up to 500)}
        {--json : Machine-readable output}';

    protected $description = 'List clients: type, stage, billing mode, retainer summary';

    public function handle(ClientDirectory $clients): int
    {
        try {
            $result = $clients->list($this->actingIdentity()->account->id, [
                'search' => $this->option('search'),
                'stage' => $this->option('stage'),
                'type' => $this->option('type'),
                'archived' => (bool) $this->option('archived'),
                'limit' => $this->option('limit'),
            ]);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($result));

            return self::SUCCESS;
        }

        $this->raw('Clients: '.count($result['clients']).' of '.$result['total'].'.');
        $lines = array_map(fn (array $client): string => $this->clientLine($client), $result['clients']);
        $this->fenced($lines !== [] ? $lines : ['  none']);

        return self::SUCCESS;
    }
}
