<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Real installs keep the integrations row of a provider the code no longer
 * ships; it must stay inert instead of surfacing as a broken card or job.
 */
final class RetiredIntegrationRowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $account = Account::create(['name' => 'Acc']);
        $this->user = User::factory()->create([
            'account_id' => $account->id,
            'first_name' => 'F',
            'last_name' => 'C',
            'email' => 'u@example.com',
            'owner' => true,
        ]);

        Integration::create([
            'account_id' => $account->id,
            'provider' => 'clockify',
            'is_enabled' => true,
            'api_key' => 'leftover-key',
            'last_synced_at' => now(),
        ]);
    }

    public function test_integrations_page_lists_only_known_providers(): void
    {
        $this->actingAs($this->user)
            ->get('/integrations')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('integrations/index')
                ->where('integrations', fn ($integrations) => collect($integrations)->pluck('provider')->sort()->values()->all() === ['infakt', 'trello']));
    }

    public function test_retired_provider_has_no_settings_page_and_cannot_sync(): void
    {
        $this->actingAs($this->user)->get('/integrations/clockify')->assertNotFound();
        $this->actingAs($this->user)->post('/integrations/clockify/sync')->assertNotFound();
        $this->actingAs($this->user)->put('/integrations/clockify', ['is_enabled' => true])->assertNotFound();

        $this->assertSame(1, Integration::where('provider', 'clockify')->count());
    }

    public function test_scheduler_schedules_nothing_for_the_retired_provider(): void
    {
        ScheduleFacade::swap($schedule = new Schedule);
        require base_path('routes/console.php');

        $this->assertFalse(collect($schedule->events())->contains(
            fn (Event $event): bool => str_contains((string) $event->command, 'clockify')
        ));
    }
}
