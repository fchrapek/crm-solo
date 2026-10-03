<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\HostExecDisabledException;
use App\Support\HostExec;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureHostExecEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! HostExec::enabled()) {
            $refused = new HostExecDisabledException;
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return $refused->render();
            }

            abort(403, $refused->getMessage());
        }

        return $next($request);
    }
}
