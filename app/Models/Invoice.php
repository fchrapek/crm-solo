<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invoice mirrored from Infakt. All monetary columns are integer grosze
 * (1/100 unit); use the `*_amount` accessors for decimal display values.
 */
final class Invoice extends Model
{
    /**
     * Accrual recognition date: the books recognise revenue by sale date
     * (when the work was delivered), falling back to the issue date for the
     * rare synced row where Infakt sent no sale_date.
     */
    public const ACCRUAL_DATE_SQL = 'COALESCE(sale_date, invoice_date)';

    protected $fillable = [
        'account_id',
        'client_id',
        'external_id',
        'number',
        'status',
        'client_company_name',
        'client_tax_code',
        'currency',
        'net_price',
        'gross_price',
        'tax_price',
        'paid_price',
        'left_to_pay',
        'invoice_date',
        'sale_date',
        'payment_date',
        'paid_date',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Documents that count toward revenue. Infakt advance invoices (ZAL)
     * carry the FULL contract net on every document, and the settlement
     * invoice (RZL) repeats it once more — so a 27 000 contract billed as
     * 3× ZAL + 1× RZL sums to 108 000. Counting each contract exactly once
     * means dropping every ZAL and keeping settlements plus regular
     * invoices. Verified against the accountant's 2023 general ledger
     * (2026-08-27, within 2%).
     */
    public function scopeCountsAsRevenue(Builder $query): Builder
    {
        // NULL number must stay in: `NOT LIKE` is never true for NULL, and a
        // row the sync could not number is still not an advance.
        return $query->where(function (Builder $q): void {
            $q->whereNull('number')->orWhere('number', 'not like', '%/ZAL/%');
        });
    }

    /**
     * Invoices recognised in a period on the accrual basis (by invoice date).
     */
    public function scopeIssuedBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()]);
    }

    /**
     * Invoices recognised in a period on the cash basis (by paid date).
     * Only paid invoices carry a paid_date, so this naturally excludes unpaid.
     */
    public function scopePaidBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query
            ->whereNotNull('paid_date')
            ->whereBetween('paid_date', [$from->toDateString(), $to->toDateString()]);
    }

    protected function casts(): array
    {
        return [
            'net_price' => 'integer',
            'gross_price' => 'integer',
            'tax_price' => 'integer',
            'paid_price' => 'integer',
            'left_to_pay' => 'integer',
            'invoice_date' => 'date',
            'sale_date' => 'date',
            'payment_date' => 'date',
            'paid_date' => 'date',
        ];
    }
}
