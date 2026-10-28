<?php

declare(strict_types=1);

namespace App\Services\Clients\Fields;

use App\Models\ClientFieldDefinition;
use App\Services\Agent\AgentRefusal;
use App\Services\Messaging\MessageScheduler;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The one place an account's client fields are defined, changed and archived. */
final class ClientFieldDefinitions
{
    /** @return Collection<int, ClientFieldDefinition> */
    public function list(int $accountId, bool $withArchived = false): Collection
    {
        return ClientFieldDefinition::query()
            ->where('account_id', $accountId)
            ->when(! $withArchived, fn ($query) => $query->active())
            ->ordered()
            ->get();
    }

    /**
     * Creates the field, or changes its label, options, required flag or order.
     * The key and the type never change; an option key, once stored, stays.
     *
     * @param  array{label?: ?string, type?: ?string, options?: ?list<array{key: string, label: string}>, required?: ?bool, on_overview?: ?bool, sort?: ?int, archived?: ?bool}  $attributes
     */
    public function define(int $accountId, string $key, array $attributes): ClientFieldDefinition
    {
        $field = DB::transaction(fn (): ClientFieldDefinition => $this->defineLocked($accountId, $key, $attributes));

        // Archiving or restoring a date field changes which reminders should wait.
        if ($field->wasChanged('archived_at')) {
            app(MessageScheduler::class)->recomputeAccount($accountId);
        }

        return $field;
    }

    /** @param array<string, mixed> $attributes */
    private function defineLocked(int $accountId, string $key, array $attributes): ClientFieldDefinition
    {
        $key = mb_trim($key);
        if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) || in_array($key, ClientFieldDefinition::RESERVED_KEYS, true)) {
            throw AgentRefusal::invalid("Field key {$key}: lowercase letters, digits and _, starting with a letter; not ".implode(', ', ClientFieldDefinition::RESERVED_KEYS).'.');
        }

        $field = ClientFieldDefinition::query()->where('account_id', $accountId)->where('key', $key)->lockForUpdate()->first()
            ?? new ClientFieldDefinition(['account_id' => $accountId, 'key' => $key]);

        $type = $attributes['type'] ?? null;
        if ($field->exists && $type !== null && $type !== $field->type) {
            throw AgentRefusal::invalid("Field {$key} is a {$field->type}; the type of an existing field does not change.");
        }
        if (! $field->exists) {
            if (! in_array($type, ClientFieldDefinition::TYPES, true)) {
                throw AgentRefusal::invalid('Pass the type: '.implode(', ', ClientFieldDefinition::TYPES).'.', ['valid_types' => ClientFieldDefinition::TYPES]);
            }
            $field->type = $type;
        }

        $label = mb_trim((string) ($attributes['label'] ?? ''));
        if ($label !== '') {
            $field->label = mb_substr($label, 0, 100);
        } elseif (! $field->exists) {
            throw AgentRefusal::invalid("Pass a label for the new field {$key}.");
        }

        if (array_key_exists('options', $attributes) && $attributes['options'] !== null) {
            $field->options = $this->options($field, $attributes['options']);
        }
        if ($field->hasOptions() && $field->optionLabels() === []) {
            throw AgentRefusal::invalid('A choice field needs at least one option.', ['field' => 'options']);
        }

        if (($attributes['required'] ?? null) !== null) {
            $field->required = (bool) $attributes['required'];
        }
        if (($attributes['on_overview'] ?? null) !== null) {
            $field->on_overview = (bool) $attributes['on_overview'];
        }
        if (($attributes['sort'] ?? null) !== null) {
            $field->sort = max(0, (int) $attributes['sort']);
        } elseif (! $field->exists) {
            $field->sort = (int) ClientFieldDefinition::query()->where('account_id', $accountId)->max('sort') + 1;
        }
        if (($attributes['archived'] ?? null) !== null) {
            $field->archived_at = $attributes['archived'] ? ($field->archived_at ?? now()) : null;
        }

        $field->save();

        return $field;
    }

    /**
     * New options are added, labels of known keys renamed; a key already
     * defined keeps its place even when the list leaves it out.
     *
     * @param  list<array{key: string, label: string}>  $given
     * @return list<array{key: string, label: string}>
     */
    private function options(ClientFieldDefinition $field, array $given): array
    {
        $merged = $field->optionLabels();
        foreach ($given as $option) {
            $key = mb_trim((string) ($option['key'] ?? ''));
            $label = mb_trim((string) ($option['label'] ?? $key));
            if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $key) || $label === '') {
                throw AgentRefusal::invalid("Option key {$key}: lowercase letters, digits, _ and -, with a label.");
            }
            $merged[$key] = mb_substr($label, 0, 100);
        }

        // A label typed instead of a key must name one option.
        $folded = array_map(mb_strtolower(...), $merged);
        if (count(array_unique($folded)) !== count($folded)) {
            throw AgentRefusal::invalid("Two options of {$field->key} would share a label.");
        }

        return array_map(fn (string $key, string $label): array => ['key' => $key, 'label' => $label], array_keys($merged), $merged);
    }
}
