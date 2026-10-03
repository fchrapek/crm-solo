<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\DemoWriteBudget;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LimitDemoTextWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! DemoWriteBudget::inputTooLarge($request)) {
            return $next($request);
        }

        $message = __('The demo accepts at most :kb KB of text per save.', ['kb' => (int) config('app.demo_writes.max_text_kb')]);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        return back()->with('error', $message);
    }
}
