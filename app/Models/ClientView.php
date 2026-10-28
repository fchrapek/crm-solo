<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * When a signed-in user last opened a client's record; "nowe" on Przegląd and Dziennik is measured from it.
 *
 * @property int $id
 * @property int $user_id
 * @property int $client_id
 * @property \Illuminate\Support\Carbon $viewed_at
 */
final class ClientView extends Model
{
    protected $fillable = [
        'user_id',
        'client_id',
        'viewed_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
        ];
    }
}
