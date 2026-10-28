<?php

declare(strict_types=1);

namespace App\Services\Clients\Fields;

use App\Models\Client;
use App\Models\ClientFieldDefinition;
use App\Models\ClientFieldEvent;
use App\Models\User;
use App\Services\Agent\AgentRefusal;
use App\Services\Messaging\MessageScheduler;
use Illuminate\Support\Facades\DB;

/**
 * The one write path for a client's custom fields and assignee, used by the
 * form, the verbs and the MCP tools. Several fields change together or not at
 * all, under the client row lock, and every change leaves a ClientFieldEvent.
 */
final class ClientFieldWriter
{
    public function __construct(private readonly MessageScheduler $messages) {}

    /**
     * @param  array<string, mixed>  $values  field key => raw value; null or '' clears
     * @return list<array{key: string, old: mixed, new: mixed, event_id: int}> what actually changed
     */
    public function set(Client $client, array $values, ?User $actor = null): array
    {
        if ($values === []) {
            throw AgentRefusal::invalid('Pass at least one field.');
        }

        return DB::transaction(function () use ($client, $values, $actor): array {
            $locked = Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $fields = ClientFieldDefinition::query()->where('account_id', $locked->account_id)->get()->keyBy('key');
            $stored = (array) ($locked->custom_fields ?? []);
            $changes = [];

            foreach ($values as $key => $raw) {
                $field = $fields->get($key);
                if ($field === null || $field->archived_at !== null) {
                    throw AgentRefusal::invalid("No active field {$key}.", ['valid_fields' => $fields->whereNull('archived_at')->keys()->values()->all()]);
                }

                $new = FieldValue::normalise($field, $raw);
                if ($new === null && $field->required) {
                    throw AgentRefusal::invalid("{$field->label} is required and cannot be cleared.", ['field' => $key]);
                }

                $old = $stored[$key] ?? null;
                if ($old === $new) {
                    continue;
                }

                if ($new === null) {
                    unset($stored[$key]);
                } else {
                    $stored[$key] = $new;
                }

                $event = $this->record($locked, $actor, $key, $field->label, $old, $new, FieldValue::display($field, $old), FieldValue::display($field, $new));
                $changes[] = ['key' => $key, 'old' => $old, 'new' => $new, 'event_id' => $event->id];
            }

            if ($changes !== []) {
                $locked->forceFill(['custom_fields' => $stored === [] ? null : $stored])->save();
                $client->setRawAttributes($locked->getAttributes(), true);
                $this->messages->fieldsChanged($locked, $changes);
            }

            return $changes;
        });
    }

    /** @return bool whether the assignee changed */
    public function assign(Client $client, ?User $assignee, ?User $actor = null): bool
    {
        if ($assignee !== null && ($assignee->account_id !== $client->account_id || $assignee->trashed())) {
            throw AgentRefusal::invalid('The assignee must be an active user of the same account.');
        }

        return DB::transaction(function () use ($client, $assignee, $actor): bool {
            $locked = Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $old = $locked->assigned_user_id;
            if ($old === $assignee?->id) {
                return false;
            }

            $oldName = $old !== null ? User::withTrashed()->find($old)?->name : null;
            $locked->forceFill(['assigned_user_id' => $assignee?->id])->save();
            $client->setRawAttributes($locked->getAttributes(), true);
            $this->record($locked, $actor, 'assignee', 'Assignee', $old, $assignee?->id, $oldName, $assignee?->name);

            return true;
        });
    }

    private function record(Client $client, ?User $actor, string $key, string $label, mixed $old, mixed $new, ?string $oldDisplay, ?string $newDisplay): ClientFieldEvent
    {
        return ClientFieldEvent::create([
            'client_id' => $client->id,
            'user_id' => $actor?->id,
            'field_key' => $key,
            'label' => $label,
            'old_value' => $old,
            'new_value' => $new,
            'old_display' => $oldDisplay,
            'new_display' => $newDisplay,
        ]);
    }
}
