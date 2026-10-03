<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Requests are built the way the demo receives them: Traefik (a trusted
 * Docker peer) forwarding "visitor, cloudflare-edge" plus Cloudflare's
 * CF-Connecting-IP.
 */
final class ClientIpBehindCloudflareTest extends TestCase
{
    use RefreshDatabase;

    private const TRAEFIK = '172.18.0.2';

    private const EDGE = '104.16.0.1';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.demo' => true,
            'trustedproxy.proxies' => '172.16.0.0/12',
            'trustedproxy.cloudflare_client_ip' => true,
        ]);
        $this->user = User::factory()->create(['account_id' => Account::create(['name' => 'Demo', 'is_test' => true])->id, 'owner' => true]);
        Route::get('/_client-ip', fn () => request()->ip());
    }

    public function test_visitors_behind_one_edge_resolve_to_their_own_address(): void
    {
        $this->viaCloudflare('198.51.100.10')->get('/_client-ip')->assertSeeText('198.51.100.10');
        $this->viaCloudflare('198.51.100.11')->get('/_client-ip')->assertSeeText('198.51.100.11');

        config(['trustedproxy.cloudflare_client_ip' => false]);
        $this->viaCloudflare('198.51.100.10')->get('/_client-ip')->assertSeeText(self::EDGE);
    }

    public function test_two_visitors_behind_one_edge_get_separate_buckets(): void
    {
        foreach (range(1, 30) as $_) {
            $this->actingAs($this->user)->viaCloudflare('198.51.100.10')->postJson('/demo/cli', ['command' => 'crm today'])->assertOk();
        }
        $this->actingAs($this->user)->viaCloudflare('198.51.100.10')->postJson('/demo/cli', ['command' => 'crm today'])->assertStatus(429);

        $this->actingAs($this->user)->viaCloudflare('198.51.100.11')->postJson('/demo/cli', ['command' => 'crm today'])->assertOk();
    }

    public function test_the_login_throttle_is_per_visitor_behind_one_edge(): void
    {
        foreach (range(1, 6) as $_) {
            $this->viaCloudflare('198.51.100.10')->post('/login', ['email' => $this->user->email, 'password' => 'wrong']);
        }
        $this->viaCloudflare('198.51.100.10')
            ->post('/login', ['email' => $this->user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->viaCloudflare('198.51.100.11')
            ->post('/login', ['email' => $this->user->email, 'password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_an_untrusted_peer_cannot_choose_its_bucket_with_a_forged_header(): void
    {
        $direct = fn (string $forged) => $this->actingAs($this->user)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->withHeaders(['CF-Connecting-IP' => $forged, 'X-Forwarded-For' => $forged]);

        $direct('198.51.100.99')->get('/_client-ip')->assertSeeText('203.0.113.50');

        foreach (range(1, 30) as $i) {
            $direct('198.51.100.'.$i)->postJson('/demo/cli', ['command' => 'crm today'])->assertOk();
        }
        $direct('198.51.100.200')->postJson('/demo/cli', ['command' => 'crm today'])->assertStatus(429);
    }

    public function test_a_malformed_header_from_a_trusted_peer_falls_back_to_the_forwarded_chain(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::TRAEFIK])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.10, '.self::EDGE, 'CF-Connecting-IP' => 'not-an-ip'])
            ->get('/_client-ip')
            ->assertSeeText(self::EDGE);
    }

    public function test_an_ipv6_cloudflare_hop_is_honoured(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::TRAEFIK])
            ->withHeaders(['X-Forwarded-For' => '2606:4700::6810:1', 'CF-Connecting-IP' => '2001:db8::5'])
            ->get('/_client-ip')
            ->assertSeeText('2001:db8::5');
    }

    public function test_a_forged_header_through_traefik_from_outside_cloudflare_is_ignored(): void
    {
        // Straight to the origin: Traefik is trusted, but the hop before it is the attacker.
        $direct = fn (string $forged) => $this->actingAs($this->user)
            ->withServerVariables(['REMOTE_ADDR' => self::TRAEFIK])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.50', 'CF-Connecting-IP' => $forged]);

        $direct('198.51.100.99')->get('/_client-ip')->assertSeeText('203.0.113.50');

        foreach (range(1, 30) as $i) {
            $direct('198.51.100.'.$i)->postJson('/demo/cli', ['command' => 'crm today'])->assertOk();
        }
        $direct('198.51.100.200')->postJson('/demo/cli', ['command' => 'crm today'])->assertStatus(429);
    }

    public function test_a_header_with_several_values_is_ignored(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::TRAEFIK])
            ->withHeaders(['X-Forwarded-For' => self::EDGE, 'CF-Connecting-IP' => '198.51.100.10, 198.51.100.11'])
            ->get('/_client-ip')
            ->assertSeeText(self::EDGE);
    }

    private function viaCloudflare(string $visitor): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::TRAEFIK])
            ->withHeaders(['X-Forwarded-For' => $visitor.', '.self::EDGE, 'CF-Connecting-IP' => $visitor]);
    }
}
