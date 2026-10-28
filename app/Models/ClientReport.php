<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\HumanizedText;
use App\Services\Clients\HoursBank;
use App\Support\LocalCalendar;
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
        'needs_recalculation',
        'generated_at',
        'finalized_at',
        'sent_at',
    ];

    /**
     * Of reports ending on the same day, the one the hours bank reads: sent, then finalized, then a draft; a whole month before a partial period; then the newest row.
     *
     * @param  iterable<self>  $reports
     */
    public static function governing(iterable $reports): ?self
    {
        return collect($reports)
            ->sortBy([
                fn (self $a, self $b): int => self::statusRank($b->status) <=> self::statusRank($a->status),
                fn (self $a, self $b): int => (int) $b->coversWholeMonth() <=> (int) $a->coversWholeMonth(),
                fn (self $a, self $b): int => $b->id <=> $a->id,
            ])
            ->first();
    }

    /**
     * Of reports ending on the same day, the one that settles that day: from the account's baseline month on only a
     * whole month counts, older partial periods still do; then governing().
     *
     * @param  iterable<self>  $reports
     */
    public static function settling(iterable $reports, string $baseline): ?self
    {
        $baselineDay = LocalCalendar::monthFrom($baseline)->toDateString();

        return self::governing(collect($reports)->filter(fn (self $report): bool => $report->settlesFrom($baselineDay)));
    }

    public static function statusRank(?string $status): int
    {
        return match ($status) {
            self::STATUS_SENT => 3,
            self::STATUS_FINALIZED => 2,
            default => 1,
        };
    }

    /** The report already covering exactly this period: generating it again refuses rather than duplicating. */
    public static function coveringPeriod(int $clientId, string $start, string $end): ?self
    {
        return self::governing(self::query()
            ->where('client_id', $clientId)
            ->whereDate('period_start', $start)
            ->whereDate('period_end', $end)
            ->get());
    }

    /** Whether this report can be a settled point for an account whose baseline month starts on $baselineDay. */
    public function settlesFrom(string $baselineDay): bool
    {
        return $this->period_end->toDateString() < $baselineDay || $this->coversWholeMonth();
    }

    public function coversWholeMonth(): bool
    {
        return $this->period_type === self::PERIOD_MONTH
            && $this->period_start->day === 1
            && $this->period_end->toDateString() === $this->period_start->copy()->endOfMonth()->toDateString();
    }

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
        return HoursBank::available(
            (float) $this->opening_balance_hours,
            (float) $this->contracted_hours,
            $this->rollover_cap_hours !== null ? (float) $this->rollover_cap_hours : null,
        );
    }

    /**
     * What carries into the next period. Can go negative: the client overran
     * and starts the next month in debt, which is the honest reading.
     */
    public function closingBalanceHours(): float
    {
        // Without contracted hours the period was billed outside a retainer, so its hours never draw on the bank.
        if ($this->contracted_hours === null) {
            return round((float) $this->opening_balance_hours, 2);
        }

        return HoursBank::closing($this->availableHours(), (float) $this->actual_hours);
    }

    /**
     * Hours lost to the ceiling this period. Worth surfacing rather than
     * silently dropping — it is the client's money.
     */
    public function forfeitedHours(): float
    {
        return HoursBank::forfeited(
            (float) $this->opening_balance_hours,
            (float) $this->contracted_hours,
            $this->rollover_cap_hours !== null ? (float) $this->rollover_cap_hours : null,
        );
    }

    /**
     * Fingerprint of what an editor works on, the text and the balance; an edit
     * sent with an older fingerprint was made on a version that is gone.
     */
    public function version(): string
    {
        return sha1($this->body_markdown."\0".$this->opening_balance_hours);
    }

    /** The local calendar day the report was marked as sent. */
    public function sentOn(): ?string
    {
        return $this->sent_at?->copy()->setTimezone(LocalCalendar::timezone())->toDateString();
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
            'finalized_at_before' => $this->finalized_at,
            'sent_at_before' => $this->sent_at,
            'contracted_hours_before' => $this->contracted_hours,
            'actual_hours_before' => $this->actual_hours,
            'opening_balance_hours_before' => $this->opening_balance_hours,
            'rollover_cap_hours_before' => $this->rollover_cap_hours,
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
            'needs_recalculation' => 'boolean',
        ];
    }
}
