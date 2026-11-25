<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * A page fetched ahead on hover (Inertia sends `Purpose: prefetch`, a browser's own prefetch `Sec-Purpose`): it may
 * never be shown, so it reads only and leaves every side effect to the visit.
 */
final class PrefetchRequest
{
    public static function is(Request $request): bool
    {
        foreach (['Purpose', 'Sec-Purpose'] as $header) {
            if (str_starts_with(mb_strtolower((string) $request->headers->get($header)), 'prefetch')) {
                return true;
            }
        }

        return false;
    }
}
