<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-Timing for DevTools, timed from this middleware's start: LARAVEL_START
 * is set once per Octane worker, not per request.
 */
final class ServerTiming
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = hrtime(true);

        $response = $next($request);

        $response->headers->set('Server-Timing', sprintf('app;dur=%.1F', (hrtime(true) - $start) / 1e6));

        return $response;
    }
}
