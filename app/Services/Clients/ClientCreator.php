<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;

/**
 * The one way a client is created by hand: the client form, `crm
 * client-create` and MCP `client_create`. The model's created hook writes the
 * first journal entry and the general project.
 */
final class ClientCreator
{
    /**
     * @param  array<string, mixed>  $attributes  validated client columns
     * @param  array{first_name: string, last_name: string, email?: ?string, phone?: ?string}|null  $contact
     * @return array{client: Client, contact: Contact|null}
     */
    public function create(int $accountId, array $attributes, ?array $contact = null): array
    {
        return DB::transaction(function () use ($accountId, $attributes, $contact): array {
            $client = Client::create([...$attributes, 'account_id' => $accountId]);

            $person = null;
            if ($contact !== null) {
                $email = $contact['email'] ?? null;
                $person = Contact::create([
                    'account_id' => $accountId,
                    'client_id' => $client->id,
                    'first_name' => $contact['first_name'],
                    'last_name' => $contact['last_name'],
                    'emails' => $email !== null && $email !== '' ? [$email] : null,
                    'phone' => $contact['phone'] ?? null,
                ]);
            }

            return ['client' => $client, 'contact' => $person];
        });
    }
}
