<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** The audit record of one close or reopen, with the figures it froze or discarded; rows are written once and never changed. */
final class ClientMonthSettlementEvent extends Model
{
    public const string ACTION_CLOSE = 'close';

    public const string ACTION_REOPEN = 'reopen';

    public const null UPDATED_AT = null;

    protected $fillable = [
        'client_id',
        'period',
        'action',
        'closure_id',
        'snapshot',
        'actor_user_id',
        'created_at',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Settlement events are append-only.'));
        self::deleting(fn (): never => throw new LogicException('Settlement events are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
