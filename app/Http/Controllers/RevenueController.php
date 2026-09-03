<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Revenue\RevenueAggregator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Inertia\Inertia;
use Throwable;

final class RevenueController extends Controller
{
    public const PERIODS = ['this_month', 'this_quarter', 'ytd', 'last_12_months', 'custom'];

    public function __construct(private readonly RevenueAggregator $aggregator) {}

    public function index()
    {
        $accountId = Auth::user()->account_id;

        $period = (string) Request::input('period', 'ytd');
        if (! in_array($period, self::PERIODS, true)) {
            $period = 'ytd';
        }

        $basis = Request::input('basis') === RevenueAggregator::BASIS_CASH
            ? RevenueAggregator::BASIS_CASH
            : RevenueAggregator::BASIS_ACCRUAL;

        [$from, $to] = $this->resolvePeriod($period);

        // All currencies are converted to PLN (the account base) inside the
        // aggregator, so the page shows one combined "PL value".
        $data = $this->aggregator->build($accountId, $from, $to, $basis);

        return Inertia::render('revenue/index', [
            'filters' => [
                'period' => $period,
                'basis' => $basis,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'summary' => $data['summary'],
            'clients' => $data['clients'],
            'trend' => $data['trend'],
            'fx' => $data['fx'],
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvePeriod(string $period): array
    {
        $now = Carbon::now();

        return match ($period) {
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'this_quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
            'last_12_months' => [$now->copy()->subMonthsNoOverflow(11)->startOfMonth(), $now->copy()->endOfMonth()],
            'custom' => [
                $this->parseDate(Request::input('from'), $now->copy()->startOfYear()),
                $this->parseDate(Request::input('to'), $now->copy()->endOfDay()),
            ],
            default => [$now->copy()->startOfYear(), $now->copy()->endOfDay()], // ytd
        };
    }

    private function parseDate(mixed $value, Carbon $fallback): Carbon
    {
        if (! is_string($value) || $value === '') {
            return $fallback;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return $fallback;
        }
    }
}
