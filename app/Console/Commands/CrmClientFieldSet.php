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

/** Agent verb: set one or more custom fields of a client in one write. */
#[AccountScope(AccountScope::ACTING)]
final class CrmClientFieldSet extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:client-field-set
        {client : Client id or name fragment}
        {values* : key=value pairs; an empty value clears the field; multiselect values are comma separated}
        {--json : Machine-readable output}';

    protected $description = 'Set custom fields of a client (key=value, several at once)';

    public function handle(CrmEntityResolver $resolver, ClientFieldWriter $writer): int
    {
        $identity = $this->actingIdentity();

        $values = [];
        foreach ((array) $this->argument('values') as $pair) {
            if (! str_contains((string) $pair, '=')) {
                return $this->refusal(AgentRefusal::invalid("Pass key=value, got {$pair}."));
            }
            [$key, $value] = explode('=', (string) $pair, 2);
            $values[mb_trim($key)] = $value;
        }

        try {
            $client = $resolver->client((string) $this->argument('client'), $identity->account->id);
            $changes = $writer->set($client, $values, $identity->user);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson(['client_id' => $client->id, 'changed' => $changes]));

            return self::SUCCESS;
        }

        $this->raw($changes === []
            ? 'Nothing changed for '.$this->literal($client->name).'.'
            : 'Updated '.$this->literal($client->name).': '.$this->literal(implode(', ', array_column($changes, 'key'))).'.');

        return self::SUCCESS;
    }
}
