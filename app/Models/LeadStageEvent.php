<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\HumanizedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit row for lead stage moves — the LeadStageEvent mirror of
 * ClientLifecycleEvent.
 *
 * This table is the funnel measurement: the Month-2 checkpoint replaces the
 * plan's assumed conversion rates with rates computed from these timestamps.
 * `from_stage` is null exactly once per lead — the capture event, which is a
 * creation rather than a transition.
 */
final class LeadStageEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'lead_id',
        'user_id',
        'from_stage',
        'to_stage',
        'note',
        'created_at',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
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
