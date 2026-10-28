<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * When a template goes out. trigger_type 'field_value': when the field takes one
 * of `values` (any value when null); 'date_offset': `offset_minutes` from the
 * date in `field` (negative is before), optionally at a local `at` time.
 */
final class MessageRule extends Model
{
    public const string FIELD_VALUE = 'field_value';

    public const string DATE_OFFSET = 'date_offset';

    /** The column defaults, so a rule just created reads as it is stored. */
    protected $attributes = [
        'expire_minutes' => 120,
        'enabled' => true,
    ];

    protected $fillable = [
        'account_id',
        'key',
        'template_id',
        'trigger_type',
        'trigger_config',
        'expire_minutes',
        'enabled',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class);
    }

    public function field(): string
    {
        return (string) ($this->trigger_config['field'] ?? '');
    }

    /** @return array<string, mixed> */
    public function toAgentArray(): array
    {
        return [
            'key' => $this->key,
            'template' => $this->template?->key,
            'trigger' => $this->trigger_type,
            ...$this->trigger_config,
            'expire_minutes' => $this->expire_minutes,
            'enabled' => $this->enabled,
        ];
    }

    protected function casts(): array
    {
        return [
            'trigger_config' => 'array',
            'expire_minutes' => 'integer',
            'enabled' => 'boolean',
        ];
    }
}
