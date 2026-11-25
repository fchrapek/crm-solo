<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\LegacyUrls;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A retired URL answers a permanent redirect to its new one, reads only.
 * A redirected write would be lost: fetch() follows a 301 on a POST as a GET.
 */
final class RedirectLegacyUrls
{
    public function __construct(private readonly LegacyUrls $urls) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getRealMethod(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $target = $this->urls->translate($request->getPathInfo(), (string) $request->server->get('QUERY_STRING', ''));
        if ($target === null) {
            return $next($request);
        }

        // A week, so a browser that cached a wrong rule heals once the rule is fixed.
        return new RedirectResponse($target, 301, ['Cache-Control' => 'public, max-age=604800']);
    }
}
