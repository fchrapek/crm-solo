<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Http\Requests\ClientsRequest;
use App\Models\Client;
use App\Services\Clients\ClientBillingMode;
use App\Services\Clients\ClientCreator;
use App\Services\Clients\ClientNameMatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

/**
 * A new client from `crm client-create` and MCP `client_create`: the basics
 * only (name, type, a contact, NIP, a note), checked with the client form's
 * rules, refused when the name is close to an existing client unless `force`.
 * Retainer terms stay in the app.
 */
final class ClientIntake
{
    /** Accepted spellings of the two types an agent sets, onto the model's vocabulary. */
    private const array TYPES = [
        'retainer' => 'retainer',
        'one_off' => 'one_off',
        'one-off' => 'one_off',
        'oneoff' => 'one_off',
    ];

    private const int CONTACT_PART_MAX = 25;

    public function __construct(
        private readonly ClientCreator $creator,
        private readonly ClientNameMatcher $matcher,
    ) {}

    /**
     * @param  array{name?: ?string, type?: ?string, contact?: ?string, email?: ?string, phone?: ?string, nip?: ?string, note?: ?string, force?: ?bool}  $input
     * @return array<string, mixed>
     *
     * @throws AgentRefusal
     */
    public function create(int $accountId, array $input): array
    {
        $name = mb_trim((string) ($input['name'] ?? ''));
        $type = $this->type($input['type'] ?? null);
        $email = $this->optional($input['email'] ?? null);
        $phone = $this->optional($input['phone'] ?? null);
        $nip = $this->optional($input['nip'] ?? null);
        $nip = $nip !== null ? (string) preg_replace('/[\s-]+/', '', $nip) : null;

        $attributes = [
            'type' => 'business',
            'name' => $name,
            'cooperation_type' => $type,
            'email' => $email,
            'phone' => $phone,
            'tax_id' => $nip,
            'notes' => $this->optional($input['note'] ?? null),
        ];
        $rules = array_intersect_key((new ClientsRequest)->rules(), $attributes);
        $validator = Validator::make($attributes, $rules);
        if ($validator->fails()) {
            $errors = $validator->errors()->toArray();
            if (isset($errors['tax_id'])) {
                $errors['nip'] = $errors['tax_id'];
                unset($errors['tax_id']);
            }

            throw AgentRefusal::invalid('The client was not created: '.implode(' ', $validator->errors()->all()), ['errors' => $errors]);
        }

        $contact = $this->contact($input['contact'] ?? null, $email, $phone);

        $force = (bool) ($input['force'] ?? false);

        // Two calls for the same name at once must not both pass the guard.
        return Cache::lock("agent:client-create:{$accountId}", 10)->block(5, fn (): array => $this->guardAndCreate($accountId, $name, $attributes, $contact, $force));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array{first_name: string, last_name: string, email: ?string, phone: ?string}|null  $contact
     * @return array<string, mixed>
     */
    private function guardAndCreate(int $accountId, string $name, array $attributes, ?array $contact, bool $force): array
    {
        if (! $force) {
            $matches = $this->matcher->similar($accountId, $name);
            if ($matches->isNotEmpty()) {
                throw new AgentRefusal(
                    AgentRefusal::POSSIBLE_DUPLICATE,
                    "A client with a name close to \"{$name}\" exists. Check it is a different company, then call again with force to create it anyway.",
                    ['candidates' => $matches->map(fn (Client $client): array => [
                        'id' => (int) $client->id,
                        'name' => (string) $client->name,
                        'context' => $client->trashed() ? 'archived' : $client->lifecycle_stage,
                    ])->all(), 'untrusted' => ['candidates']],
                );
            }
        }

        ['client' => $client, 'contact' => $person] = $this->creator->create($accountId, $attributes, $contact);

        return [
            'client' => ClientDirectory::item($client->fresh() ?? $client, null, ClientBillingMode::ORDER),
            'contact_id' => $person?->id,
            'forced' => $force,
        ];
    }

    private function type(?string $type): ?string
    {
        $type = $this->optional($type);
        if ($type === null) {
            return null;
        }

        return self::TYPES[mb_strtolower($type)]
            ?? throw AgentRefusal::invalid("Unknown client type \"{$type}\".", ['valid_types' => ['retainer', 'one_off']]);
    }

    /**
     * "Jan Kowalski" is a first and a last name; one word is a first name.
     *
     * @return array{first_name: string, last_name: string, email: ?string, phone: ?string}|null
     */
    private function contact(?string $name, ?string $email, ?string $phone): ?array
    {
        $name = $this->optional($name);
        if ($name === null) {
            return null;
        }

        $parts = preg_split('/\s+/', $name, 2) ?: [$name];
        $first = $parts[0];
        $last = $parts[1] ?? '';
        if (mb_strlen($first) > self::CONTACT_PART_MAX || mb_strlen($last) > self::CONTACT_PART_MAX) {
            throw AgentRefusal::invalid('A contact first or last name is longer than '.self::CONTACT_PART_MAX.' characters.', ['field' => 'contact']);
        }

        return ['first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone];
    }

    private function optional(?string $value): ?string
    {
        $value = mb_trim((string) $value);

        return $value === '' ? null : $value;
    }
}
