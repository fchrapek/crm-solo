<?php

declare(strict_types=1);

namespace App\Services\Revenue;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Converts foreign-currency amounts to PLN using NBP (Narodowy Bank Polski)
 * mid rates (table A), cached for a day. PLN is the account base currency, so
 * revenue analytics can present one combined "PL value" even when invoices are
 * issued in USD/EUR/etc.
 *
 * Tests can pre-seed the cache key `fx_rate_pln_{CUR}` to avoid the HTTP call.
 */
final class FxRateService
{
    private const CACHE_TTL_HOURS = 24;

    /**
     * Last-resort rates used only if NBP is unreachable and nothing is cached,
     * so a network blip never zeroes out or wildly misstates a revenue total.
     * Logged loudly when hit.
     */
    private const FALLBACK = ['USD' => 3.95, 'EUR' => 4.30, 'GBP' => 5.05];

    public function rateToPln(string $currency): float
    {
        $currency = mb_strtoupper($currency);
        if ($currency === 'PLN') {
            return 1.0;
        }

        // Cast: some cache stores (redis/valkey) round-trip floats as strings.
        return (float) Cache::remember(
            "fx_rate_pln_{$currency}",
            now()->addHours(self::CACHE_TTL_HOURS),
            fn (): float => $this->fetchFromNbp($currency)
        );
    }

    public function toPln(float $amount, string $currency): float
    {
        return $amount * $this->rateToPln($currency);
    }

    private function fetchFromNbp(string $currency): float
    {
        try {
            $response = Http::timeout(10)
                ->get("https://api.nbp.pl/api/exchangerates/rates/A/{$currency}/", ['format' => 'json']);

            $mid = $response->json('rates.0.mid');
            if ($response->successful() && is_numeric($mid)) {
                return (float) $mid;
            }

            throw new RuntimeException("NBP returned no rate for {$currency} (status {$response->status()})");
        } catch (Throwable $e) {
            $fallback = self::FALLBACK[$currency] ?? 1.0;
            Log::warning('FX rate fetch failed, using fallback', [
                'currency' => $currency,
                'fallback' => $fallback,
                'error' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }
}
