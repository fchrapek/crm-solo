<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One accepted form submission: its replay key, payload fingerprint and the outbox state of its lead notice. */
final class LeadIntake extends Model
{
    public const string WAITING = 'waiting';

    public const string SKIPPED = 'skipped';

    public const string SENT = 'sent';

    public const string FAILED = 'failed';

    /** Older notices are skipped, never sent. */
    public const int MAX_AGE_DAYS = 7;

    protected $fillable = [
        'account_id',
        'intake_form_id',
        'lead_id',
        'idempotency_key',
        'fingerprint',
        'notice_state',
        'failure_reason',
        'notify_attempts',
        'notified_at',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(IntakeForm::class, 'intake_form_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withTrashed();
    }

    protected function casts(): array
    {
        return [
            'notify_attempts' => 'integer',
            'notified_at' => 'datetime',
        ];
    }
}
