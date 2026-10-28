<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ClientFieldEvent extends Model
{
    protected $fillable = [
        'client_id',
        'user_id',
        'field_key',
        'label',
        'old_value',
        'new_value',
        'old_display',
        'new_display',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'old_value' => 'json',
            'new_value' => 'json',
        ];
    }
}
