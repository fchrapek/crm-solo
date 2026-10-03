<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

/**
 * Upload caps for the public demo, where every visitor shares one login: a
 * small per-file size and a per-IP hourly count. Outside DEMO_MODE neither
 * applies and each upload route keeps its own size cap.
 */
final class UploadLimits
{
    public static function maxKilobytes(int $normal): int
    {
        return config('app.demo') ? min($normal, (int) config('app.demo_uploads.max_kb')) : $normal;
    }

    public static function limit(Request $request): Limit
    {
        if (! config('app.demo')) {
            return Limit::none();
        }

        return Limit::perHour((int) config('app.demo_uploads.per_hour'))->by('uploads|'.$request->ip());
    }
}
