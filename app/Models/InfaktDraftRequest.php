<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A maintenance draft the CRM asked Infakt for, one per client, month and
 * invoice group. The states and who may move them are documented on
 * InfaktDraftRun; `attempt` numbers each send and guards every write.
 */
final class InfaktDraftRequest extends Model
{
    /** An attempt has been claimed and its request is in flight until lease_expires_at. */
    public const string STATUS_SENDING = 'sending';

    /** Infakt accepted the request and gave a task reference; the draft is being built. */
    public const string STATUS_SUBMITTED = 'submitted';

    public const string STATUS_CREATED = 'created';

    /** Infakt documented that nothing was created (422); safe to send again. */
    public const string STATUS_FAILED = 'failed';

    /** The outcome is unknown; Infakt may hold the draft. Blocks the group until checked. */
    public const string STATUS_UNCONFIRMED = 'unconfirmed';

    protected $fillable = [
        'account_id',
        'client_id',
        'period',
        'invoice_group',
        'status',
        'attempt',
        'lease_expires_at',
        'payload_fingerprint',
        'task_reference',
        'invoice_uuid',
        'error',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** A send whose lease ran out never reported back: as uncertain as an unanswered one. */
    public function isLost(): bool
    {
        return $this->status === self::STATUS_UNCONFIRMED
            || ($this->status === self::STATUS_SENDING && ($this->lease_expires_at === null || $this->lease_expires_at->isPast()));
    }

    protected function casts(): array
    {
        return [
            'invoice_group' => 'integer',
            'attempt' => 'integer',
            'lease_expires_at' => 'datetime',
        ];
    }
}
