<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\HumanizedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MessageTemplate extends Model
{
    public const string SMS = 'sms';

    public const string EMAIL = 'email';

    protected $fillable = [
        'account_id',
        'key',
        'channel',
        'name',
        'subject',
        'body',
        'is_default',
    ];

    protected $attributes = [
        'channel' => self::SMS,
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    protected function casts(): array
    {
        return [
            'subject' => HumanizedText::class,
            'body' => HumanizedText::class,
            'is_default' => 'boolean',
        ];
    }
}
