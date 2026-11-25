<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Account settings on the public demo: every visitor shares the account, so they are shown and never saved.
 */
final class ReadOnlyInDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.demo') || $request->isMethodSafe()) {
            return $next($request);
        }

        $message = __('Settings are read-only in the demo.');

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        // As an error of the form too, so a dialog stays open with what was typed.
        return back()->withErrors(['demo' => $message])->with('error', $message);
    }
}
