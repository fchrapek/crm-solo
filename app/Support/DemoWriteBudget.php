<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

/**
 * What one demo visitor may write: every state-changing request counts against
 * a per-IP burst and hourly budget, and no single request may carry more than
 * a small amount of input. Reads are free; outside DEMO_MODE nothing applies.
 * The nightly demo:reset clears whatever accumulates.
 */
final class DemoWriteBudget
{
    /** @return Limit|list<Limit> */
    public static function limit(Request $request): Limit|array
    {
        if (! config('app.demo') || $request->isMethodSafe()) {
            return Limit::none();
        }

        return [
            Limit::perMinute((int) config('app.demo_writes.per_minute'))->by('demo-writes-minute|'.$request->ip()),
            Limit::perHour((int) config('app.demo_writes.per_hour'))->by('demo-writes-hour|'.$request->ip()),
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
