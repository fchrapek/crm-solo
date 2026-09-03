<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesClients;
use Illuminate\Console\Command;

/**
 * Agent verb: append a journal entry to a client's timeline (a same-stage
 * lifecycle event with a note — the exact mechanism the UI's "+ Add log
 * entry" composer uses, so agent notes and human notes share one history).
 */
final class CrmNote extends Command
{
    use ResolvesClients;

    protected $signature = 'crm:note {client : Client id or name fragment} {note : Journal entry text} {--json : Machine-readable output}';

    protected $description = "Append a journal entry to a client's timeline";

    public function handle(): int
    {
        $client = $this->resolveClient((string) $this->argument('client'));
        if ($client === null) {
            return self::FAILURE;
        }

        $note = mb_trim((string) $this->argument('note'));
        if ($note === '') {
            $this->error('The note is empty.');

            return self::FAILURE;
        }

        $event = $client->transitionTo($client->lifecycle_stage, $note, null);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'client_id' => $client->id,
                'event_id' => $event?->id,
            ], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Noted on {$client->name} (event #{$event?->id}).");

        return self::SUCCESS;
    }
}
