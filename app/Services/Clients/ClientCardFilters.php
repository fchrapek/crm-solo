<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientFieldDefinition;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Clients\Fields\ClientFieldsQuery;
use App\Services\Clients\Fields\FieldValue;
use App\Support\LocalCalendar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Klienci for an account with its own fields (Figma 606:4373): the overview
 * fields as columns, filters by assignee and by any choice field, each facet
 * counted with the other filters applied, and the SMS line above the table.
 */
final class ClientCardFilters
{
    /** An account that bills keeps its hours columns; its own fields stay on the client's Dane. */
    public function uses(int $accountId): bool
    {
        return app(ClientFieldsQuery::class)->uses($accountId) && ! Account::query()->findOrFail($accountId)->hasFeature('billing');
    }

    /**
     * @param  array<string, mixed>  $input  assignee (me, none or a user id) and f[field]=option from the query string
     * @return array{billing: bool, user_id: ?int, assignee: int|string|null, fields: array<string, string>, definitions: Collection<int, ClientFieldDefinition>, users: array<int, string>}
     */
    public function parse(int $accountId, array $input, ?int $userId): array
    {
        $definitions = ClientFieldDefinition::query()->where('account_id', $accountId)->active()->ordered()->get();
        $users = User::query()->where('account_id', $accountId)->orderByName()->get()->mapWithKeys(fn (User $user): array => [$user->id => $user->name])->all();

        $raw = $input['assignee'] ?? null;
        $assignee = match (true) {
            $raw === 'me' && $userId !== null => 'me',
            $raw === 'none' => 'none',
            is_numeric($raw) && isset($users[(int) $raw]) => (int) $raw,
            default => null,
        };

        $fields = [];
        foreach ((array) ($input['f'] ?? []) as $key => $option) {
            $field = $definitions->firstWhere('key', $key);
            if ($field !== null && $field->type === 'select' && is_string($option) && array_key_exists($option, $field->optionLabels())) {
                $fields[$key] = $option;
            }
        }

        return [
            'billing' => false,
            'user_id' => $userId,
            'assignee' => $assignee,
            'fields' => $fields,
            'definitions' => $definitions,
            'users' => $users,
        ];
    }

    /**
     * @param  Builder<Client>  $query
     * @param  array<string, mixed>  $card
     * @return Builder<Client>
     */
    public function apply(Builder $query, array $card): Builder
    {
        $assignee = $card['assignee'] === 'me' ? $card['user_id'] : $card['assignee'];
        $query->when($assignee === 'none', fn (Builder $q) => $q->whereNull('assigned_user_id'))
            ->when(is_int($assignee), fn (Builder $q) => $q->where('assigned_user_id', $assignee));

        // Keys and options were checked against the definitions, so the JSON path is safe.
        foreach ($card['fields'] as $key => $option) {
            $query->where("custom_fields->{$key}", $option);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array{fields: array<string, ?string>, assignee: ?string}
     */
    public function row(Client $client, array $card): array
    {
        $values = (array) ($client->custom_fields ?? []);

        return [
            'fields' => $card['definitions']->where('on_overview', true)
                ->mapWithKeys(fn (ClientFieldDefinition $field): array => [$field->key => FieldValue::readable($field, $values[$field->key] ?? null)])->all(),
            'assignee' => $card['users'][$client->assigned_user_id] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  Builder<Client>  $book  the clients the stage and cooperation filters leave
     * @return array<string, mixed>
     */
    public function props(int $accountId, array $card, Builder $book): array
    {
        $rows = $book->get(['id', 'assigned_user_id', 'custom_fields']);
        $count = fn (?string $skip, callable $extra): int => $rows->filter(fn (Client $client): bool => $this->matches($client, $card, $skip) && $extra($client))->count();
        $assigned = fn (Client $client): ?int => $client->assigned_user_id;
        $today = LocalCalendar::today();

        return [
            'billing' => $card['billing'],
            'filters' => ['assignee' => $card['assignee'], 'f' => (object) $card['fields']],
            'columns' => $card['definitions']->where('on_overview', true)->map(fn (ClientFieldDefinition $field): array => ['key' => $field->key, 'label' => $field->label])->values()->all(),
            'assignees' => [
                ...($card['user_id'] !== null ? [['value' => 'me', 'label' => null, 'count' => $count('assignee', fn (Client $c): bool => $assigned($c) === $card['user_id'])]] : []),
                ['value' => null, 'label' => null, 'count' => $count('assignee', fn (): bool => true)],
                ...collect($card['users'])->map(fn (string $name, int $id): array => ['value' => $id, 'label' => $name, 'count' => $count('assignee', fn (Client $c): bool => $assigned($c) === $id)])->values()->all(),
                ['value' => 'none', 'label' => null, 'count' => $count('assignee', fn (Client $c): bool => $assigned($c) === null)],
            ],
            'facets' => $card['definitions']->where('type', 'select')->map(fn (ClientFieldDefinition $field): array => [
                'key' => $field->key,
                'label' => $field->label,
                'options' => [
                    ['value' => null, 'label' => null, 'count' => $count($field->key, fn (): bool => true)],
                    ...collect($field->optionLabels())->map(fn (string $label, string $option): array => [
                        'value' => $option,
                        'label' => $label,
                        'count' => $count($field->key, fn (Client $c): bool => (((array) $c->custom_fields)[$field->key] ?? null) === $option),
                    ])->values()->all(),
                ],
            ])->values()->all(),
            'sms' => [
                'today' => OutboundMessage::query()->where('account_id', $accountId)->where('status', OutboundMessage::PENDING)
                    ->whereHas('client', fn (Builder $client) => $client->where('sms_opt_out', false))
                    ->whereBetween('scheduled_for', [$today->copy()->startOfDay()->utc(), $today->copy()->endOfDay()->utc()])->count(),
                'failed' => OutboundMessage::query()->where('account_id', $accountId)->where('status', OutboundMessage::FAILED)
                    ->where('scheduled_for', '>=', now()->subDays(7))->count(),
            ],
        ];
    }

    /** @param array<string, mixed> $card */
    private function matches(Client $client, array $card, ?string $skip): bool
    {
        $assignee = $card['assignee'] === 'me' ? $card['user_id'] : $card['assignee'];
        if ($skip !== 'assignee' && $assignee !== null && ($assignee === 'none' ? $client->assigned_user_id !== null : $client->assigned_user_id !== $assignee)) {
            return false;
        }
        $values = (array) ($client->custom_fields ?? []);
        foreach ($card['fields'] as $key => $option) {
            if ($key !== $skip && ($values[$key] ?? null) !== $option) {
                return false;
            }
        }

        return true;
    }
}
