<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\HumanizedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A client's month-end summary email; its states and who moves them are documented on SummaryEmails. */
final class ClientSummaryEmail extends Model
{
    public const string DRAFT = 'draft';

    /** Claimed for one attempt and handed to the queue; the job hands it to the mailer. */
    public const string QUEUED = 'queued';

    /** Delivered live to the client: locked, and the month-close step ticks. */
    public const string SENT = 'sent';

    /** Went to the log, the array transport or the redirect address: still a draft in all but name. */
    public const string TEST_SENT = 'test_sent';

    /** The mailer refused it, so nothing reached anyone; it may be sent again. */
    public const string FAILED = 'failed';

    /** It may or may not have reached the client; only the owner's explicit resend sends it again. */
    public const string UNCONFIRMED = 'unconfirmed';

    /** How long a queued send may wait for its job before it counts as lost. */
    public const int LEASE_MINUTES = 15;

    protected $fillable = [
        'account_id',
        'client_id',
        'period',
        'template_id',
        'recipients',
        'bcc_owner',
        'subject',
        'body',
        'status',
    ];

    protected $attributes = [
        'status' => self::DRAFT,
        'bcc_owner' => true,
        'attempt' => 0,
    ];

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->where($field ?? 'id', $value)
            ->where('account_id', auth()->user()->account_id)
            ->firstOrFail();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id')->withTrashed();
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::TEST_SENT, self::FAILED], true);
    }

    /** Queued with its lease run out: the job never reported back. */
    public function isLost(): bool
    {
        return $this->status === self::QUEUED && ($this->lease_expires_at === null || $this->lease_expires_at->isPast());
    }

    /** Lost before the job reached the mailer, so nothing can have gone out. */
    public function neverHandedOff(): bool
    {
        return $this->isLost() && $this->handed_off_at === null;
    }

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'bcc_owner' => 'boolean',
            'subject' => HumanizedText::class,
            'body' => HumanizedText::class,
            'attempt' => 'integer',
            'lease_expires_at' => 'datetime',
            'handed_off_at' => 'datetime',
            'sent_at' => 'datetime',
            'drafted_at' => 'datetime',
            'text_edited_at' => 'datetime',
            'step_ticked_at' => 'datetime',
        ];
    }
}
