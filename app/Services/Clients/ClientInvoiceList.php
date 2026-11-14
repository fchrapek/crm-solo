<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\Invoice;
use App\Services\Revenue\RevenueAggregator;
use App\Support\LocalCalendar;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** A client's invoices from the Infakt mirror for a year, a month or a rolling year, with the revenue of the same window. */
final class ClientInvoiceList
{
    /** A client's whole history fits inside this at solo scale, so it guards the payload instead of paging. */
    public const int LIMIT = 100;

    public function __construct(private readonly RevenueAggregator $revenue) {}

    /**
     * Status is the only payment signal: Infakt marks documents paid without settling the amount columns.
     *
     * @return array<string, mixed>
     */
    public static function row(Invoice $invoice, Carbon $today): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'currency' => $invoice->currency ?? 'PLN',
            'net' => (float) ($invoice->net_price / 100),
            'gross' => (float) ($invoice->gross_price / 100),
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'sale_date' => $invoice->sale_date?->toDateString(),
            'payment_date' => $invoice->payment_date?->toDateString(),
            'paid_date' => $invoice->paid_date?->toDateString(),
            'overdue' => $invoice->isOverdueOn($today),
            'external_url' => $invoice->infaktUrl(),
        ];
    }

    /**
     * @param  string|null  $year  a year, `all` or `last12`; the local year when missing
     * @param  string|null  $month  1 to 12 within a year
     * @return array{rows: list<array<string, mixed>>, total: int, truncated: bool, filters: array{year: int|string, month: int|null, years: list<int>}, revenue: array<string, mixed>|null}
     */
    public function props(Client $client, ?string $year, ?string $month): array
    {
        $yearParam = $year ?? (string) LocalCalendar::now()->year;
        $lastTwelve = $yearParam === 'last12';
        $invoiceYear = $yearParam === 'all' || $lastTwelve ? null : (int) $yearParam;
        $invoiceMonth = ! $lastTwelve && is_numeric($month) && (int) $month >= 1 && (int) $month <= 12 ? (int) $month : null;

        // The rolling year counts by the books' date (sale date first) from the local today, as the revenue page does.
        $twelveTo = LocalCalendar::todayDate();
        $twelveFrom = $twelveTo->copy()->subYear();

        $filtered = fn (): HasMany => $client->invoices()
            ->when($lastTwelve, fn ($q) => $q->whereBetween(DB::raw(Invoice::ACCRUAL_DATE_SQL), [$twelveFrom->toDateString(), $twelveTo->toDateString()]))
            ->when($invoiceYear !== null, fn ($q) => $q->whereYear('invoice_date', $invoiceYear))
            ->when($invoiceMonth !== null, fn ($q) => $q->whereMonth('invoice_date', $invoiceMonth));

        $total = $filtered()->count();

        $from = null;
        $to = null;
        if ($lastTwelve) {
            $from = $twelveFrom;
            $to = $twelveTo;
        } elseif ($invoiceYear !== null) {
            $from = Carbon::create($invoiceYear, $invoiceMonth ?? 1, 1)->startOfDay();
            $to = $invoiceMonth !== null ? $from->copy()->endOfMonth() : $from->copy()->endOfYear();
        }

        return [
            'rows' => $filtered()->limit(self::LIMIT)->get()->map(fn (Invoice $invoice): array => self::row($invoice, $twelveTo))->values()->all(),
            'total' => $total,
            'truncated' => $total > self::LIMIT,
            'filters' => [
                'year' => $lastTwelve ? 'last12' : ($invoiceYear ?? 'all'),
                'month' => $invoiceMonth,
                // Distinct years in PHP: YEAR() and strftime() differ per driver, and a client's list is small.
                'years' => $client->invoices()
                    ->whereNotNull('invoice_date')
                    ->pluck('invoice_date')
                    ->map(fn ($date): int => (int) $date->format('Y'))
                    ->unique()
                    ->sortDesc()
                    ->values()
                    ->all(),
            ],
            // The same window as the rows, so filtering the list refilters the figures over it; no bounds is all time.
            'revenue' => $this->revenue->clientSummary($client->account_id, $client->id, $from, $to),
        ];
    }
}
