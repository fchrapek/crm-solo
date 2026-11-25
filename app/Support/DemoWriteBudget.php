<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\GuardIntakeRequests;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * What a demo visitor may write: every state-changing request counts against a per-visitor budget and one all
 * visitors share, carries a small amount of input at most, and marks the demo as changed for the hourly reset.
 */
final class DemoWriteBudget
{
    private const string CHANGED = 'demo-changed';

    /** Whether a visitor has written anything since the last reset; a yes clears it. */
    public static function takeChanged(): bool
    {
        // Never pull(): it forgets after a "no" as well, and would drop a mark set in between.
        if (! Cache::get(self::CHANGED, false)) {
            return false;
        }
        Cache::forget(self::CHANGED);

        return true;
    }

    public static function markChanged(): void
    {
        Cache::forever(self::CHANGED, true);
    }

    /** One visitor: an IPv4 address, or the /64 an IPv6 host usually holds whole. */
    public static function visitor(Request $request): string
    {
        return GuardIntakeRequests::clientKey($request->ip());
    }

    /** @return Limit|list<Limit> */
    public static function limit(Request $request): Limit|array
    {
        if (! config('app.demo') || $request->isMethodSafe()) {
            return Limit::none();
        }

        self::markChanged();
        $visitor = self::visitor($request);

        return [
            Limit::perMinute((int) config('app.demo_writes.per_minute'))->by('demo-writes-minute|'.$visitor),
            Limit::perHour((int) config('app.demo_writes.per_hour'))->by('demo-writes-hour|'.$visitor),
            Limit::perHour((int) config('app.demo_writes.total_per_hour'))->by('demo-writes-total'),
        ];
    }

    /**
     * Size in bytes of the whole non-file input serialized: keys, every scalar
     * and the nesting all count. Uploaded files are not part of input() and
     * have their own limits. Input that cannot be serialized counts as too large.
     */
    public static function inputBytes(Request $request): int
    {
        $json = json_encode($request->input(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? PHP_INT_MAX : mb_strlen($json, '8bit');
    }

    public static function inputTooLarge(Request $request): bool
    {
        return config('app.demo')
            && ! $request->isMethodSafe()
            && self::inputBytes($request) > (int) config('app.demo_writes.max_text_kb') * 1024;
    }
}
