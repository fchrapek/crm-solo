<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

final class ClientRetainer extends Model
{
    protected $fillable = [
        'account_id',
        'client_id',
        'project_id',
        'label',
        'description',
        'monthly_hours',
        'monthly_fee',
        'overage_hourly_rate',
        'invoice_group',
        'vat_symbol',
        'rollover_cap_hours',
        'is_active',
        'sort_order',
        'currency',
        'effective_from',
        'effective_to',
        'notes',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isActiveOn(Carbon $date): bool
    {
        if ($this->effective_from->gt($date)) {
            return false;
        }

        return $this->effective_to === null || $this->effective_to->gt($date);
    }

    public function scopeActiveOn(Builder $query, Carbon $date): Builder
    {
        return $query
            ->whereDate('effective_from', '<=', $date)
            ->where(function (Builder $q) use ($date): void {
                $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>', $date);
            });
    }

    /** Active positions on a date, the one a report snapshots first: most hours, then the newest. */
    public function scopeLeadingOn(Builder $query, Carbon $date): Builder
    {
        return $query
            ->activeOn($date)
            ->where('is_active', true)
            ->reorder()
            ->orderByDesc('monthly_hours')
            ->orderByDesc('effective_from')
            ->orderByDesc('id');
    }

    /**
     * What the abonament dialog edits; the dialog sends every field back, so nothing it does not show is cleared.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'label' => $this->label,
            'description' => $this->description,
            'monthly_hours' => (float) $this->monthly_hours,
            'monthly_fee' => $this->monthly_fee !== null ? (float) $this->monthly_fee : null,
            'overage_hourly_rate' => $this->overage_hourly_rate !== null ? (float) $this->overage_hourly_rate : null,
            'rollover_cap_hours' => $this->rollover_cap_hours !== null ? (float) $this->rollover_cap_hours : null,
            'invoice_group' => $this->invoice_group,
            'vat_symbol' => $this->vat_symbol,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'currency' => $this->currency,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'notes' => $this->notes,
        ];
    }

    protected function casts(): array
    {
        return [
            'monthly_hours' => 'decimal:2',
            'monthly_fee' => 'decimal:2',
            'overage_hourly_rate' => 'decimal:2',
            'rollover_cap_hours' => 'decimal:2',
            'invoice_group' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
