<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Peers whose X-Forwarded-* headers are believed (read by Laravel's
    | TrustProxies middleware). Loopback covers the local DDEV/traefik route
    | and the docker nginx; a deployment names its own proxy's address or
    | subnet in TRUSTED_PROXIES (comma-separated IPs or CIDRs). Every other
    | peer's forwarded headers are ignored, so the client IP behind the login
    | throttle is the right-most address no trusted proxy vouched for.
    |
    */

    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1,::1'),

    /*
    | Behind Cloudflare, take the visitor address from CF-Connecting-IP, but
    | only when the request came through a trusted proxy AND the address that
    | connected to that proxy is one of Cloudflare's (App\Http\Middleware\
    | TrustProxies). Anything else, including a forged header sent straight to
    | the origin, falls back to the normal forwarded chain.
    */

    'cloudflare_client_ip' => (bool) env('TRUST_CF_CONNECTING_IP', false),

    /*
    | Cloudflare's published edge ranges, from https://www.cloudflare.com/ips/
    | (ips-v4 and ips-v6, fetched 2026-10-01). They change rarely; refresh by
    | replacing this list, or set CLOUDFLARE_IP_RANGES (comma-separated CIDRs)
    | from `curl -s https://www.cloudflare.com/ips-v4 https://www.cloudflare.com/ips-v6`.
    | Never fetched at request time.
    */

    'cloudflare_ranges' => env('CLOUDFLARE_IP_RANGES')
        ? array_values(array_filter(array_map('trim', explode(',', (string) env('CLOUDFLARE_IP_RANGES')))))
        : [
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
            '2400:cb00::/32',
            '2606:4700::/32',
            '2803:f800::/32',
            '2405:b500::/32',
            '2405:8100::/32',
            '2a06:98c0::/29',
            '2c0f:f248::/32',
        ],

];
