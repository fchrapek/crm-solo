<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\HumanizedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MessageTemplate extends Model
{
    protected $fillable = [
        'account_id',
        'key',
        'name',
        'body',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    protected function casts(): array
    {
        return [
            'body' => HumanizedText::class,
        ];
    }
}
