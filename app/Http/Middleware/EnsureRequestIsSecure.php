<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behind a TLS-terminating proxy that forwards plain http without a
 * trustworthy X-Forwarded-Proto (Cloudflare Flexible -> Traefik), the app
 * sees every request as http. URL::forceScheme() covers *generated* URLs,
 * but anything derived from the request itself still says http — most
 * visibly the intended URL stored on a guest redirect: the post-login 302
 * then points at http://, the browser blocks the XHR follow as mixed
 * content, and the first login click dies silently while the session is
 * already authenticated.
 *
 * If the deployment declares itself https (APP_URL), every request IS
 * https from the visitor's point of view — mark it so before anything
 * reads it.
 */
final class EnsureRequestIsSecure
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isSecure() && Str::startsWith((string) config('app.url'), 'https://')) {
            // Both layers matter: when the request comes from a trusted proxy
            // (Traefik), Symfony gives the forwarded headers precedence over
            // the server vars — an X-Forwarded-Proto saying "http" would win
            // over HTTPS=on, so overwrite it too.
            $request->headers->set('X-Forwarded-Proto', 'https');
            $request->headers->set('X-Forwarded-Port', '443');
            $request->server->set('HTTPS', 'on');
            $request->server->set('SERVER_PORT', 443);
        }

        return $next($request);
    }
}
