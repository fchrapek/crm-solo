<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client's month frozen in the hours bank. The snapshot is authoritative for the month's figures:
 * hours are decimals rounded to 2 places, null means not applicable (no cap, no report).
 */
final class ClientMonthClosure extends Model
{
    public const int SNAPSHOT_SCHEMA_VERSION = 1;

    protected $fillable = [
        'client_id',
        'period',
        'closed_at',
        'closed_by_user_id',
        'snapshot',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function closingHours(): float
    {
        return (float) $this->snapshot['closing'];
    }

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }
}
