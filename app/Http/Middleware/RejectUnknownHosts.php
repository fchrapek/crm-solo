<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers only the hosts this install is served under, in every environment:
 * a page on another hostname that resolves to this machine (DNS rebinding)
 * must not reach a controller. Laravel's TrustHosts is a no-op in local, so
 * the check lives here. Both the raw Host and any X-Forwarded-Host are
 * checked, because a loopback peer is a trusted proxy and a browser can set
 * the forwarded header itself.
 */
final class RejectUnknownHosts
{
    /**
     * APP_URL's host, the loopback names (a rebinding page cannot send those
     * as its Host) and APP_ALLOWED_HOSTS.
     *
     * @return list<string>
     */
    public static function allowedHosts(): array
    {
        $hosts = ['localhost', '127.0.0.1', '[::1]'];

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $hosts[] = self::hostname($appHost);
        }

        foreach ((array) config('app.allowed_hosts', []) as $extra) {
            $hosts[] = self::hostname((string) $extra);
        }

        return array_values(array_unique(array_filter($hosts, fn (string $host) => $host !== '')));
    }

    public function handle(Request $request, Closure $next): Response
    {
        $allowed = self::allowedHosts();

        $candidates = [(string) $request->headers->get('host')];
        $forwarded = $request->headers->get('x-forwarded-host');
        if ($forwarded !== null) {
            $candidates = [...$candidates, ...explode(',', $forwarded)];
        }

        foreach ($candidates as $candidate) {
            $host = self::hostname($candidate);
            if ($host === '' || ! in_array($host, $allowed, true)) {
                return new Response('Unknown host.', Response::HTTP_BAD_REQUEST, ['Content-Type' => 'text/plain']);
            }
        }

        return $next($request);
    }

    /** Lowercased hostname without port or trailing dot; IPv6 keeps its brackets. */
    private static function hostname(string $value): string
    {
        $value = mb_strtolower(mb_trim($value));

        if (str_starts_with($value, '[')) {
            $end = mb_strpos($value, ']');

            return $end === false ? '' : mb_substr($value, 0, $end + 1);
        }

        if (mb_substr_count($value, ':') > 1) {
            return '['.$value.']';
        }

        return mb_rtrim(explode(':', $value)[0], '.');
    }
}
