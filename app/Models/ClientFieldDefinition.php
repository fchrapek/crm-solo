<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A field one account adds to its clients. The key is immutable (values are
 * stored under it), the label and option labels may change; select options are
 * {key, label} pairs so a renamed option keeps its stored values.
 */
final class ClientFieldDefinition extends Model
{
    public const array TYPES = ['text', 'textarea', 'select', 'multiselect', 'date', 'datetime', 'url'];

    /** Keys the CRM itself uses next to the custom ones. */
    public const array RESERVED_KEYS = ['assignee'];

    /** The column defaults, so a field just created reads as it is stored. */
    protected $attributes = [
        'sort' => 0,
        'required' => false,
        'on_overview' => false,
    ];

    protected $fillable = [
        'account_id',
        'key',
        'label',
        'type',
        'options',
        'sort',
        'required',
        'on_overview',
        'archived_at',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function hasOptions(): bool
    {
        return in_array($this->type, ['select', 'multiselect'], true);
    }

    /** @return array<string, string> option key => label */
    public function optionLabels(): array
    {
        $labels = [];
        foreach ((array) $this->options as $option) {
            if (is_array($option) && is_string($option['key'] ?? null)) {
                $labels[$option['key']] = (string) ($option['label'] ?? $option['key']);
            }
        }

        return $labels;
    }

    /** @return array<string, mixed> */
    public function toAgentArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'options' => $this->hasOptions() ? array_values((array) $this->options) : null,
            'required' => $this->required,
            'on_overview' => $this->on_overview,
            'archived' => $this->archived_at !== null,
            'untrusted' => ['label', 'options'],
        ];
    }

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'required' => 'boolean',
            'on_overview' => 'boolean',
            'sort' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }
}
