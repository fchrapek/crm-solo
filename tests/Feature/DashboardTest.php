<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Agent-first dashboard: the daily-session prop is the surface. The old
 * attention-task widget was removed 2026-07-29 — attention flows from
 * `crm today` (see CrmVerbsTest) and the client record views.
 */
final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Test Account']);
        $this->user = User::factory()->create([
            'account_id' => $this->account->id,
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'owner' => true,
        ]);
    }

    public function test_dashboard_ships_the_daily_session_snapshot(): void
    {
        $this->actingAs($this->user)
            ->get('/sesje')
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('dashboard', false)
                ->has('dailySession', fn (Assert $session) => $session
                    ->has('available')
                    ->has('agents')
                    ->has('attach')
                    ->etc()
                )
            );
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/')->assertRedirect('/login');
    }
}
