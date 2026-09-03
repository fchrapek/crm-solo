<?php

declare(strict_types=1);

namespace App\Services\Revenue;

use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates synced Infakt invoices into per-client revenue analytics for a
 * period and recognition basis. All amounts are converted to PLN (the account
 * base currency) via {@see FxRateService} using current NBP rates, so a single
 * "PL value" combines PLN + USD/EUR invoices.
 *
 * Recognition basis:
 *  - accrual: invoices counted by `sale_date` (when the work was delivered —
 *    the accountant books by sale date, so a January invoice for December
 *    work lands in December's year)
 *  - cash:    invoices counted by `paid_date` (when paid; unpaid excluded)
 *
 * Advance invoices (ZAL) are excluded everywhere via
 * {@see Invoice::scopeCountsAsRevenue}: each ZAL repeats the full contract
 * net, so summing them overcounts revenue (the 2023 books were off by 111k
 * before this filter).
 *
 * Both net and gross are returned; net/gross is a display toggle. Money is in
 * major units (grosze ÷ 100), PLN.
 */
final class RevenueAggregator
{
    public const BASIS_ACCRUAL = 'accrual';

    public const BASIS_CASH = 'cash';

    public function __construct(private readonly FxRateService $fx) {}

    /**
     * @return array{
     *   summary: array<string, mixed>,
     *   clients: array<int, array<string, mixed>>,
     *   trend: array<int, array<string, mixed>>,
     *   fx: array<string, mixed>
     * }
     */
    public function build(int $accountId, Carbon $from, Carbon $to, string $basis): array
    {
        $length = $from->diffInDays($to) + 1;
        $priorTo = (clone $from)->subDay();
        $priorFrom = (clone $priorTo)->subDays($length - 1);

        $current = $this->perClient($accountId, $from, $to, $basis);
        $prior = $this->perClient($accountId, $priorFrom, $priorTo, $basis);
        $priorByClient = $prior->keyBy('client_key');

        $totalNet = (float) $current->sum('net');
        $totalGross = (float) $current->sum('gross');
        $priorTotalNet = (float) $prior->sum('net');
        $priorTotalGross = (float) $prior->sum('gross');

        $clients = $current
            ->map(function (array $row) use ($priorByClient, $totalNet, $totalGross): array {
                $priorRow = $priorByClient->get($row['client_key']);
                $priorNet = $priorRow['net'] ?? 0.0;
                $priorGross = $priorRow['gross'] ?? 0.0;

                return [
                    ...$row,
                    'share_net' => $totalNet > 0 ? round($row['net'] / $totalNet * 100, 1) : 0.0,
                    'share_gross' => $totalGross > 0 ? round($row['gross'] / $totalGross * 100, 1) : 0.0,
                    'trend_net' => $this->pctDelta($priorNet, $row['net']),
                    'trend_gross' => $this->pctDelta($priorGross, $row['gross']),
                ];
            })
            ->sortByDesc('net')
            ->values()
            ->all();

        return [
            'summary' => [
                'total_net' => round($totalNet, 2),
                'total_gross' => round($totalGross, 2),
                'trend_net' => $this->pctDelta($priorTotalNet, $totalNet),
                'trend_gross' => $this->pctDelta($priorTotalGross, $totalGross),
                'invoice_count' => (int) $current->sum('invoice_count'),
                'client_count' => $current->count(),
                'avg_per_client_net' => $current->count() > 0 ? round($totalNet / $current->count(), 2) : 0.0,
                'avg_per_client_gross' => $current->count() > 0 ? round($totalGross / $current->count(), 2) : 0.0,
                'top3_share_net' => $this->topNShare($current, 'net', $totalNet, 3),
                'top3_share_gross' => $this->topNShare($current, 'gross', $totalGross, 3),
                'currency' => 'PLN',
                'basis' => $basis,
            ],
            'clients' => $clients,
            'trend' => $this->monthlyTrend($accountId, $from, $to, $basis),
            'fx' => $this->fxInfo($accountId),
        ];
    }

    /**
     * Compact PLN revenue summary for one client, for the Faktury tab.
     * Accrual basis (by sale_date), advances excluded. The period window
     * follows the tab's year/month filter; null bounds mean all-time.
     * Share = this client's slice of the whole book within the same window.
     * Returns null when the client has no invoices at all (last_invoice_date
     * still looks at every document, ZAL included — an advance is real
     * billing activity even though it is not revenue).
     *
     * @return array<string, mixed>|null
     */
    public function clientSummary(int $accountId, int $clientId, ?Carbon $from = null, ?Carbon $to = null): ?array
    {
        $base = fn () => Invoice::where('account_id', $accountId)->where('client_id', $clientId);

        if ((clone $base())->doesntExist()) {
            return null;
        }

        $revenue = fn () => $base()->countsAsRevenue();

        $window = fn ($query) => $from !== null && $to !== null
            ? $query->whereBetween(DB::raw(Invoice::ACCRUAL_DATE_SQL), [$from->toDateString(), $to->toDateString()])
            : $query;

        $period = $this->sumToPln($window($revenue()));
        $allTime = $this->sumToPln($revenue());
        $accountPeriodNet = $this->sumToPln($window(Invoice::where('account_id', $accountId)->countsAsRevenue()))['net'];

        return [
            'currency' => 'PLN',
            'period_net' => round($period['net'], 2),
            'period_gross' => round($period['gross'], 2),
            'period_invoice_count' => $period['count'],
            'alltime_net' => round($allTime['net'], 2),
            'alltime_gross' => round($allTime['gross'], 2),
            'share_net' => $accountPeriodNet > 0 ? round($period['net'] / $accountPeriodNet * 100, 1) : 0.0,
            'last_invoice_date' => (clone $base())->max('invoice_date'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function availableCurrencies(int $accountId): array
    {
        return Invoice::where('account_id', $accountId)
            ->select('currency')
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency')
            ->all();
    }

    /**
     * Per-client net/gross/count/last-date for the window, summed in PLN across
     * all invoice currencies. Unmatched invoices (client_id null) collapse into
     * a single "Other" bucket.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function perClient(int $accountId, Carbon $from, Carbon $to, string $basis): Collection
    {
        $dateColumn = $basis === self::BASIS_CASH ? 'paid_date' : Invoice::ACCRUAL_DATE_SQL;

        // Group by (client, currency) so each currency bucket can be converted
        // at its own rate before being folded into the client's PLN total.
        $rows = Invoice::query()
            ->where('account_id', $accountId)
            ->countsAsRevenue()
            ->when($basis === self::BASIS_CASH, fn ($q) => $q->whereNotNull('paid_date'))
            ->whereBetween(DB::raw($dateColumn), [$from->toDateString(), $to->toDateString()])
            ->selectRaw(
                'client_id, currency, COUNT(*) as invoice_count, '.
                'SUM(net_price) as net_grosze, SUM(gross_price) as gross_grosze, '.
                "MAX({$dateColumn}) as last_date"
            )
            ->groupBy('client_id', 'currency')
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $names = Client::where('account_id', $accountId)
            ->withTrashed()
            ->whereIn('id', $rows->pluck('client_id')->filter()->all())
            ->pluck('name', 'id');

        $byClient = [];
        foreach ($rows as $row) {
            $key = $row->client_id ?? 'other';
            $rate = $this->fx->rateToPln($row->currency);

            if (! isset($byClient[$key])) {
                $byClient[$key] = [
                    'client_key' => $key,
                    'client_id' => $row->client_id,
                    'name' => $row->client_id ? ($names[$row->client_id] ?? 'Unknown') : 'Other (unmatched)',
                    'invoice_count' => 0,
                    'net' => 0.0,
                    'gross' => 0.0,
                    'last_date' => null,
                ];
            }

            $byClient[$key]['invoice_count'] += (int) $row->invoice_count;
            $byClient[$key]['net'] += (float) $row->net_grosze / 100 * $rate;
            $byClient[$key]['gross'] += (float) $row->gross_grosze / 100 * $rate;
            if ($row->last_date !== null && ($byClient[$key]['last_date'] === null || $row->last_date > $byClient[$key]['last_date'])) {
                $byClient[$key]['last_date'] = $row->last_date;
            }
        }

        return collect(array_values($byClient))->map(fn (array $r): array => [
            ...$r,
            'net' => round($r['net'], 2),
            'gross' => round($r['gross'], 2),
        ]);
    }

    /**
     * Monthly PLN net/gross series across the window for the trend chart.
     *
     * @return array<int, array<string, mixed>>
     */
    private function monthlyTrend(int $accountId, Carbon $from, Carbon $to, string $basis, ?int $clientId = null): array
    {
        $dateColumn = $basis === self::BASIS_CASH ? 'paid_date' : Invoice::ACCRUAL_DATE_SQL;
        $monthExpr = $this->monthExpression($dateColumn);

        $rows = Invoice::query()
            ->where('account_id', $accountId)
            ->countsAsRevenue()
            ->when($clientId !== null, fn ($q) => $q->where('client_id', $clientId))
            ->when($basis === self::BASIS_CASH, fn ($q) => $q->whereNotNull('paid_date'))
            ->whereBetween(DB::raw($dateColumn), [$from->toDateString(), $to->toDateString()])
            ->selectRaw(
                "{$monthExpr} as month, currency, ".
                'SUM(net_price) as net_grosze, SUM(gross_price) as gross_grosze'
            )
            ->groupBy('month', 'currency')
            ->orderBy('month')
            ->get();

        $byMonth = [];
        foreach ($rows as $row) {
            $rate = $this->fx->rateToPln($row->currency);
            $byMonth[$row->month] ??= ['month' => $row->month, 'net' => 0.0, 'gross' => 0.0];
            $byMonth[$row->month]['net'] += (float) $row->net_grosze / 100 * $rate;
            $byMonth[$row->month]['gross'] += (float) $row->gross_grosze / 100 * $rate;
        }

        ksort($byMonth);

        return array_values(array_map(fn (array $m): array => [
            'month' => $m['month'],
            'net' => round($m['net'], 2),
            'gross' => round($m['gross'], 2),
        ], $byMonth));
    }

    /**
     * Sum a query's net/gross to PLN across currencies, plus invoice count.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Invoice>  $query
     * @return array{net: float, gross: float, count: int}
     */
    private function sumToPln($query): array
    {
        $rows = (clone $query)
            ->selectRaw('currency, COUNT(*) as cnt, SUM(net_price) as net_grosze, SUM(gross_price) as gross_grosze')
            ->groupBy('currency')
            ->get();

        $net = 0.0;
        $gross = 0.0;
        $count = 0;
        foreach ($rows as $row) {
            $rate = $this->fx->rateToPln($row->currency);
            $net += (float) $row->net_grosze / 100 * $rate;
            $gross += (float) $row->gross_grosze / 100 * $rate;
            $count += (int) $row->cnt;
        }

        return ['net' => $net, 'gross' => $gross, 'count' => $count];
    }

    /**
     * Conversion metadata for the UI ("USD converted at 3.67"). Lists the
     * non-PLN currencies present and the rate used for each.
     *
     * @return array{base: string, rates: array<string, float>}
     */
    private function fxInfo(int $accountId): array
    {
        $rates = [];
        foreach ($this->availableCurrencies($accountId) as $currency) {
            if ($currency !== 'PLN') {
                $rates[$currency] = round($this->fx->rateToPln($currency), 4);
            }
        }

        return ['base' => 'PLN', 'rates' => $rates];
    }

    /**
     * Portable "YYYY-MM" month-bucket SQL expression. MariaDB/MySQL use
     * DATE_FORMAT; sqlite (test DB) has no such function, so use strftime.
     */
    private function monthExpression(string $dateColumn): string
    {
        return Invoice::query()->getConnection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', {$dateColumn})"
            : "DATE_FORMAT({$dateColumn}, '%Y-%m')";
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function topNShare(Collection $rows, string $metric, float $total, int $n): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        $topSum = $rows->sortByDesc($metric)->take($n)->sum($metric);

        return round($topSum / $total * 100, 1);
    }

    private function pctDelta(float $prior, float $current): ?float
    {
        if ($prior <= 0.0) {
            return null; // no comparable prior period (avoid divide-by-zero / infinite growth)
        }

        return round(($current - $prior) / $prior * 100, 1);
    }
}
