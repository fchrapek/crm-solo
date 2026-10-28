<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use App\Services\Messaging\MessageSettings;
use Illuminate\Console\Command;

/** Agent verb: a client who asked for no text messages gets none. */
#[AccountScope(AccountScope::ACTING)]
final class CrmClientSmsOptOut extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:client-sms-opt-out
        {client : Client id or name fragment}
        {--off : Allow text messages again}
        {--json : Machine-readable output}';

    protected $description = 'Stop (or allow again) text messages to a client';

    public function handle(CrmEntityResolver $resolver, MessageSettings $settings): int
    {
        try {
            $client = $resolver->client((string) $this->argument('client'), $this->actingIdentity()->account->id);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        }

        $settings->optOut($client, ! $this->option('off'));

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson(['client_id' => $client->id, 'sms_opt_out' => (bool) $client->sms_opt_out]));

            return self::SUCCESS;
        }

        $this->raw($client->sms_opt_out ? 'Text messages stopped for:' : 'Text messages allowed again for:');
        $this->fenced(['#'.$client->id.' '.$this->literal($client->name)]);

        return self::SUCCESS;
    }
}
