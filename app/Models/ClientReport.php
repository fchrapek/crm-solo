<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\HumanizedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ClientReport extends Model
{
    public const string PERIOD_WEEK = 'week';

    public const string PERIOD_MONTH = 'month';

    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_FINALIZED = 'finalized';

    public const string STATUS_SENT = 'sent';

    protected $fillable = [
        'account_id',
        'client_id',
        'period_type',
        'period_start',
        'period_end',
        'contracted_hours',
        'actual_hours',
        'opening_balance_hours',
        'rollover_cap_hours',
        'currency',
        'composer_key',
        'body_markdown',
        'status',
        'generated_at',
        'finalized_at',
        'sent_at',
    ];

    /**
     * Hours the client can actually draw on this period: last period's balance
     * plus this period's pool, but never more than the agreed ceiling.
     *
     * The cap is on the total, not on the carried bank, so a client who leaves
     * hours unused month after month stops accumulating once the ceiling is
     * reached rather than banking indefinitely.
     */
    public function availableHours(): float
    {
        $total = (float) $this->opening_balance_hours + (float) $this->contracted_hours;
        $cap = $this->rollover_cap_hours !== null ? (float) $this->rollover_cap_hours : null;

        return $cap !== null ? min($total, $cap) : $total;
    }

    /**
     * What carries into the next period. Can go negative: the client overran
     * and starts the next month in debt, which is the honest reading.
     */
    public function closingBalanceHours(): float
    {
        return round($this->availableHours() - (float) $this->actual_hours, 2);
    }

    /**
     * Hours lost to the ceiling this period. Worth surfacing rather than
     * silently dropping — it is the client's money.
     */
    public function forfeitedHours(): float
    {
        $total = (float) $this->opening_balance_hours + (float) $this->contracted_hours;

        return round(max(0.0, $total - $this->availableHours()), 2);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ClientReportRevision::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Snapshot the current state into the revisions log BEFORE applying a
     * mutation. Reports stay editable regardless of status — the audit
     * trail is what makes that safe.
     */
    public function recordRevision(string $reason, ?User $user = null): ClientReportRevision
    {
        return $this->revisions()->create([
            'account_id' => $this->account_id,
            'user_id' => $user?->id,
            'reason' => $reason,
            'body_markdown_before' => $this->body_markdown,
            'status_before' => $this->status,
            'contracted_hours_before' => $this->contracted_hours,
            'actual_hours_before' => $this->actual_hours,
            'currency_before' => $this->currency,
            'composer_key_before' => $this->composer_key,
            'created_at' => now(),
        ]);
    }

    protected function casts(): array
    {
        return [
            'body_markdown' => HumanizedText::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'contracted_hours' => 'decimal:2',
            'actual_hours' => 'decimal:2',
            'opening_balance_hours' => 'decimal:2',
            'rollover_cap_hours' => 'decimal:2',
            'generated_at' => 'datetime',
            'finalized_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
