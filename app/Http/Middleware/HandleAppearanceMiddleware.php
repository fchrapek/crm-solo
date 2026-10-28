<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

final class HandleAppearanceMiddleware
{
    /** The offered themes (resources/js/hooks/use-theme.tsx); a retired one reads as Paper. */
    private const THEMES = ['paper', 'boring-saas'];

    public function handle(Request $request, Closure $next)
    {
        View::share('appearance', $request->cookie('appearance') ?? 'system');
        View::share('theme', in_array($request->cookie('theme'), self::THEMES, true) ? $request->cookie('theme') : 'paper');

        return $next($request);
    }
}
