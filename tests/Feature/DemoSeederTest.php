<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the public-demo dataset to the live schema and vocabulary. The demo
 * instance reseeds from DemoSeeder nightly — if a refactor renames a stage or
 * drops a column, this is the test that fails instead of the demo at 03:30.
 */
final class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_builds_the_fictional_world(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(4, Client::count());
        $this->assertSame(5, Lead::count());

        $this->assertSame(
            [],
            Client::query()->whereNotIn('lifecycle_stage', Client::LIFECYCLE_STAGES)->pluck('name')->all(),
            'Every demo client must use the current lifecycle vocabulary.',
        );

        $won = Lead::query()->where('stage', 'won')->sole();
        $this->assertNotNull($won->client_id, 'The won demo lead keeps its channel attribution.');

        $owner = User::query()->sole();
        $this->assertSame('demo@crm-solo.test', $owner->email);
    }

    /**
     * A reserved domain stops demo data pointing at a stranger's site, but the
     * company NAME is the other half. Until 2026-09-03 eight of these were real
     * registered businesses, shown on the public demo attached to invented
     * offers and invented board minutes. Plausible Polish company names are
     * almost all somebody's, so demo names now carry an explicit fiction
     * marker rather than being invented and hoped about.
     */
    public function test_every_demo_company_name_is_marked_fictional(): void
    {
        $this->seed(DemoSeeder::class);

        $names = Client::query()->pluck('name')
            ->merge(Lead::query()->pluck('company')->filter())
            ->all();

        $this->assertNotEmpty($names);

        foreach ($names as $name) {
            $this->assertMatchesRegularExpression(
                '/Przykładow|Testow/u',
                (string) $name,
                "Demo company \"{$name}\" carries no fiction marker, so it may name a real business.",
            );
        }
    }

    public function test_demo_reset_refuses_outside_demo_mode(): void
    {
        $this->artisan('demo:reset')->assertFailed();
    }
}
