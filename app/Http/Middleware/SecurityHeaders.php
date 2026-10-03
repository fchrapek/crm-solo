<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline response headers. Framing is limited to this origin; the ttyd
 * iframes the app embeds are other origins framed BY it, which these headers
 * do not govern. HSTS only over https outside local, where crm-solo.test and
 * a plain-HTTP start must stay reachable. No full CSP yet: Vite dev and
 * Inertia's inline bootstrap would need nonces first.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
        ];

        if ($request->isSecure() && ! app()->isLocal()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
