<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Services\Agent\AgentIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Who a credential-less transport acts as. This is the seam a hosted transport
 * replaces with token-derived identity, so its contract is worth pinning.
 */
final class AgentIdentityResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prefers_the_owner_over_an_earlier_user(): void
    {
        $account = Account::factory()->create();
        User::factory()->create(['account_id' => $account->id, 'owner' => false]);
        $owner = User::factory()->create(['account_id' => $account->id, 'owner' => true]);

        $identity = app(AgentIdentityResolver::class)->resolve();

        $this->assertSame($account->id, $identity->account->id);
        $this->assertSame($owner->id, $identity->user->id);
    }

    public function test_it_falls_back_to_the_first_user_when_no_owner_exists(): void
    {
        $account = Account::factory()->create();
        $first = User::factory()->create(['account_id' => $account->id, 'owner' => false]);
        User::factory()->create(['account_id' => $account->id, 'owner' => false]);

        $this->assertSame($first->id, app(AgentIdentityResolver::class)->resolve()->user->id);
    }

    public function test_it_resolves_the_lowest_numbered_account(): void
    {
        $first = Account::factory()->create();
        Account::factory()->create();

        $this->assertSame($first->id, app(AgentIdentityResolver::class)->resolve()->account->id);
    }

    public function test_an_account_without_users_degrades_to_no_attribution(): void
    {
        Account::factory()->create();

        $this->assertNull(app(AgentIdentityResolver::class)->resolve()->user);
    }

    public function test_it_throws_when_no_account_exists(): void
    {
        $this->expectException(RuntimeException::class);

        app(AgentIdentityResolver::class)->resolve();
    }
}
