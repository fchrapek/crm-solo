<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A 404, not a 403: on the public demo these features are absent, not forbidden.
 */
final class AbsentInDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if((bool) config('app.demo'), 404);

        return $next($request);
    }
}
