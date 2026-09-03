<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\Client;

/**
 * Shared client lookup for the crm:* agent verbs: numeric id or a name
 * fragment. Ambiguity is an error that LISTS the candidates — an agent can
 * immediately retry with the id instead of guessing.
 */
trait ResolvesClients
{
    private function resolveClient(string $ref): ?Client
    {
        if (ctype_digit($ref)) {
            $client = Client::find((int) $ref);
            if ($client === null) {
                $this->error("No client with id [{$ref}].");
            }

            return $client;
        }

        $matches = Client::query()->where('name', 'like', '%'.$ref.'%')->limit(6)->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->isEmpty()) {
            $this->error("No client matching [{$ref}].");

            return null;
        }

        $this->error("Ambiguous client [{$ref}] — use the id:");
        foreach ($matches as $match) {
            $this->line("  #{$match->id} {$match->name}");
        }

        return null;
    }
}
