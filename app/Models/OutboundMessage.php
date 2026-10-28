<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message to one client. Only `pending` rows are ever rescheduled; `to` and
 * `body` are frozen when the dispatcher claims the row.
 */
final class OutboundMessage extends Model
{
    public const string PENDING = 'pending';

    public const string SENDING = 'sending';

    public const string SENT = 'sent';

    public const string SIMULATED = 'simulated';

    public const string FAILED = 'failed';

    public const string CANCELLED = 'cancelled';

    public const string SKIPPED = 'skipped';

    /** A pending message whose trigger no longer holds (the date moved or was cleared). */
    public const string SUPERSEDED = 'superseded';

    protected $fillable = [
        'account_id',
        'client_id',
        'rule_id',
        'occurrence',
        'channel',
        'status',
        'scheduled_for',
        'expires_at',
        'to',
        'body',
        'provider',
        'provider_message_id',
        'error',
        'resends',
        'previous_error',
        'claimed_at',
        'sent_at',
        'cancelled_by',
    ];

    /** A refusal was answered and nothing went out; any other failure (no answer, a lost run) may have reached the phone. */
    public static function mayHaveGoneOut(?string $error): bool
    {
        return $error === null || ! preg_match('/^SMSAPI (\d+:|is not connected)/', $error);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(MessageRule::class);
    }

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
            'resends' => 'integer',
        ];
    }
}
