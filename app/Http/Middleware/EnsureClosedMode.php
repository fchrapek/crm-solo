<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Routes that exist only while the app is closed to guests (APP_CLOSED). */
final class EnsureClosedMode
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('waitlist.closed'), 404);

        return $next($request);
    }
}
