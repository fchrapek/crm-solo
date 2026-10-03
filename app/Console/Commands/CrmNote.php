<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use Illuminate\Console\Command;

/**
 * Agent verb: append a journal entry to a client's timeline (a same-stage
 * lifecycle event with a note — the exact mechanism the UI's "+ Add log
 * entry" composer uses, so agent notes and human notes share one history).
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmNote extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:note {client : Client id or name fragment} {note : Journal entry text} {--json : Machine-readable output}';

    protected $description = "Append a journal entry to a client's timeline";

    public function handle(CrmEntityResolver $resolver): int
    {
        try {
            $client = $resolver->client((string) $this->argument('client'), $this->actingIdentity()->account->id);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        }

        $note = mb_trim((string) $this->argument('note'));
        if ($note === '') {
            $this->error('The note is empty.');

            return self::FAILURE;
        }

        $event = $client->transitionTo($client->lifecycle_stage, $note, null);

        if ($this->option('json')) {
            $this->raw($this->encodeJson([
                'client_id' => $client->id,
                'event_id' => $event?->id,
            ]));

            return self::SUCCESS;
        }

        $this->raw('Noted on '.$this->literal($client->name)." (event #{$event?->id}).");

        return self::SUCCESS;
    }
}
