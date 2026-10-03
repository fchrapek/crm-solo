<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Laravel's proxy trust, plus Cloudflare's visitor address when the
 * deployment opts in (trustedproxy.cloudflare_client_ip). Behind Cloudflare
 * and Traefik the right-most untrusted X-Forwarded-For entry is a Cloudflare
 * edge, which many visitors share. CF-Connecting-IP replaces it only when the
 * peer is a trusted proxy and the hop that connected to that proxy is inside
 * Cloudflare's ranges; a request that reached the origin any other way keeps
 * its own address whatever header it sends.
 */
final class TrustProxies extends BaseTrustProxies
{
    public function handle(Request $request, Closure $next)
    {
        return parent::handle($request, function (Request $request) use ($next) {
            $this->useCloudflareClientIp($request);

            return $next($request);
        });
    }

    private function useCloudflareClientIp(Request $request): void
    {
        if (! config('trustedproxy.cloudflare_client_ip') || ! $request->isFromTrustedProxy()) {
            return;
        }

        $hop = $this->connectingHop($request);
        if ($hop === null || ! IpUtils::checkIp($hop, (array) config('trustedproxy.cloudflare_ranges', []))) {
            return;
        }

        // A single address only; a list or anything else is not Cloudflare's header.
        $visitor = mb_trim((string) $request->headers->get('CF-Connecting-IP'));
        if (filter_var($visitor, FILTER_VALIDATE_IP) === false) {
            return;
        }

        // Every request()->ip() consumer, the login throttle included, reads this.
        $request->headers->set('X-Forwarded-For', $visitor);
    }

    /** The right-most X-Forwarded-For address that is not itself a trusted proxy. */
    private function connectingHop(Request $request): ?string
    {
        $trusted = Request::getTrustedProxies();
        $chain = array_map(trim(...), explode(',', (string) $request->headers->get('X-Forwarded-For')));

        foreach (array_reverse($chain) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                return null;
            }
            if (! IpUtils::checkIp($address, $trusted)) {
                return $address;
            }
        }

        return null;
    }
}
