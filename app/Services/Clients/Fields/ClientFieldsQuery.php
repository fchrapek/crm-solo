<?php

declare(strict_types=1);

namespace App\Services\Clients\Fields;

use App\Models\Client;
use App\Models\ClientFieldDefinition;
use App\Models\ClientFieldEvent;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Messaging\MessageRows;
use App\Support\LocalCalendar;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** A client's custom fields, assignee, field history and text messages, as the record's tabs show them. */
final class ClientFieldsQuery
{
    /** An account with no fields and no rules shows none of it. */
    public function uses(int $accountId): bool
    {
        return (bool) DB::selectOne(
            'select (exists(select 1 from client_field_definitions where account_id = ?) or exists(select 1 from message_rules where account_id = ?)) as uses',
            [$accountId, $accountId],
        )->uses;
    }

    /**
     * Every field with its value, the assignee and the opt-out, plus what the edit dialog offers.
     *
     * @return array<string, mixed>
     */
    public function card(Client $client): array
    {
        $client->loadMissing('assignee');
        $values = (array) ($client->custom_fields ?? []);

        return [
            'fields' => $this->definitions($client->account_id)
                // An archived field shows only where a value is left.
                ->filter(fn (ClientFieldDefinition $field): bool => $field->archived_at === null || isset($values[$field->key]))
                ->map(fn (ClientFieldDefinition $field): array => [
                    'key' => $field->key,
                    'label' => $field->label,
                    'type' => $field->type,
                    'options' => $field->hasOptions() ? array_values((array) $field->options) : [],
                    'required' => $field->required,
                    'on_overview' => $field->on_overview,
                    'archived' => $field->archived_at !== null,
                    'display' => FieldValue::readable($field, $values[$field->key] ?? null),
                    'input' => FieldValue::input($field, $values[$field->key] ?? null),
                ])->values()->all(),
            'assignee' => $client->assignee === null ? null : ['id' => $client->assignee->id, 'name' => $client->assignee->name],
            'sms_opt_out' => (bool) $client->sms_opt_out,
            'users' => User::query()->where('account_id', $client->account_id)->orderByName()->get()
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->values()->all(),
        ];
    }

    /** @return list<array<string, mixed>> newest first */
    public function history(Client $client, int $limit = 5): array
    {
        $fields = $this->definitions($client->account_id)->keyBy('key');

        return ClientFieldEvent::query()
            ->where('client_id', $client->id)
            ->with('user:id,first_name,last_name')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (ClientFieldEvent $event): array => $this->event($event, $fields->get($event->field_key)))
            ->values()->all();
    }

    /** @return array<string, mixed> */
    public function event(ClientFieldEvent $event, ?ClientFieldDefinition $field): array
    {
        $at = $event->created_at->copy()->setTimezone(LocalCalendar::timezone());
        $read = fn (mixed $value, ?string $snapshot): ?string => $field === null ? $snapshot : (FieldValue::readable($field, $value) ?? $snapshot);

        return [
            'id' => $event->id,
            'day' => $at->toDateString(),
            'time' => $at->format('H:i'),
            'user' => $event->user?->name,
            'key' => $event->field_key,
            'label' => $field?->label ?? $event->label,
            'old' => $read($event->old_value, $event->old_display),
            'new' => $read($event->new_value, $event->new_display),
            'untrusted' => ['label', 'old', 'new'],
        ];
    }

    /**
     * The client's messages, latest planned time first. For Przegląd only what waits,
     * what failed (until it is sent again or cancelled) and what went out today.
     *
     * @return list<array<string, mixed>>
     */
    public function messages(Client $client, int $limit = 20, bool $overview = false): array
    {
        return OutboundMessage::query()
            ->where('client_id', $client->id)
            ->when($overview, fn ($query) => $query
                ->where(fn ($open) => $open->whereIn('status', [OutboundMessage::PENDING, OutboundMessage::SENDING, OutboundMessage::FAILED])
                    ->orWhere(fn ($sent) => $sent->whereIn('status', [OutboundMessage::SENT, OutboundMessage::SIMULATED])->where('sent_at', '>=', LocalCalendar::startOfTodayUtc()))))
            ->with(['rule.template', 'client.account', 'client.assignee'])
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (OutboundMessage $message): array => app(MessageRows::class)->row($message))
            ->values()->all();
    }

    /** @return Collection<int, ClientFieldDefinition> */
    private function definitions(int $accountId): Collection
    {
        return ClientFieldDefinition::query()->where('account_id', $accountId)->ordered()->get();
    }
}
