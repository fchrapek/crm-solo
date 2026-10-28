<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A 404, not a 403: a module switched off for the account is absent there.
 */
final class AccountFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless((bool) $request->user()?->account->hasFeature($feature), 404);

        return $next($request);
    }
}
