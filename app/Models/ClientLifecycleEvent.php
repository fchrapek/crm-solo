<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\HumanizedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ClientLifecycleEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'client_id',
        'user_id',
        'from_stage',
        'to_stage',
        'note',
        'created_at',
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
            'note' => HumanizedText::class,
            'created_at' => 'datetime',
        ];
    }
}
