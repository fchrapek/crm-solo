<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reproduces the demo's dead first login click: behind Cloudflare Flexible
 * the app receives plain http, so the intended URL stored on the guest
 * redirect was http:// and the browser blocked the post-login XHR redirect
 * as mixed content. EnsureRequestIsSecure marks requests secure whenever
 * APP_URL is https, so nothing request-derived can leak an http URL.
 */
final class EnsureRequestIsSecureTest extends TestCase
{
    use RefreshDatabase;

    public function test_intended_redirect_after_login_stays_https_behind_tls_terminating_proxy(): void
    {
        config(['app.url' => 'https://crm-solo.test', 'trustedproxy.proxies' => '172.16.0.0/12']);

        $user = User::factory()->create();

        // A guest hits a protected page the way the app sees it in
        // production: forwarded by a TRUSTED proxy (Traefik lives in a
        // Docker network) whose X-Forwarded-Proto honestly says http,
        // because Cloudflare terminated TLS a hop earlier. The trusted
        // header outranks server vars in Symfony, so the middleware must
        // overwrite the header itself. This stores the intended URL.
        $proxied = ['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_FORWARDED_PROTO' => 'http', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'];
        $this->withServerVariables($proxied)->get('http://crm-solo.test/')->assertRedirect();

        $response = $this->withServerVariables($proxied)->post('http://crm-solo.test/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $this->assertStringStartsWith(
            'https://',
            (string) $response->headers->get('Location'),
            'The post-login redirect must never point at http:// on an https deployment.',
        );
    }

    public function test_local_http_deployments_are_untouched(): void
    {
        config(['app.url' => 'http://localhost']);

        $this->get('http://localhost/login')->assertOk();
    }
}
