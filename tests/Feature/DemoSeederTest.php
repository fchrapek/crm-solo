<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Services\Clients\ClientBillingMode;
use App\Services\Clients\ClientListQuery;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pins the public-demo dataset to the live schema and vocabulary. The demo
 * instance reseeds from DemoSeeder nightly — if a refactor renames a stage or
 * drops a column, this is the test that fails instead of the demo at 03:30.
 */
final class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_demo_seeder_builds_the_fictional_world(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(18, Client::count());
        $this->assertSame(1, Client::onlyTrashed()->count());
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
     * A reserved domain stops demo data pointing at a stranger's site; the
     * company name is the other half. Eight of these were real businesses until
     * 2026-09-03, so names now carry an explicit fiction marker rather than
     * being invented and hoped about.
     */
    public function test_every_demo_company_name_is_marked_fictional(): void
    {
        $this->seed(DemoSeeder::class);

        $names = Client::withTrashed()->pluck('name')
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

    public function test_the_client_list_has_two_pages_every_stage_and_type_and_a_retainer_over_its_pool(): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);
        $this->travelTo(Carbon::parse('2026-10-14 08:00:00', 'UTC'));
        $this->seed(DemoSeeder::class);

        $list = app(ClientListQuery::class)->props(User::query()->sole()->account_id, []);

        $this->assertGreaterThan(ClientListQuery::PER_PAGE, $list['stage_counts']['active']);
        $this->assertSame([], array_keys(array_filter($list['stage_counts'], fn (int $count): bool => $count === 0)), 'every tab has clients');
        $types = array_diff_key($list['cooperation_counts'], [ClientListQuery::NO_TYPE => true]);
        $this->assertSame([], array_keys(array_filter($types, fn (int $count): bool => $count === 0)), 'every type has clients');
        $this->assertSame(0, $list['cooperation_counts'][ClientListQuery::NO_TYPE], 'every demo client has a type');

        $rows = collect($list['clients']->items());
        $this->assertNotEmpty($rows->filter(fn (array $row): bool => $row['hours']['has_retainer'] && $row['hours']['remaining'] < 0), 'a retainer runs over its pool');
        $this->assertNotEmpty($rows->filter(fn (array $row): bool => $row['hours']['has_retainer'] && $row['hours']['remaining'] > 0), 'a retainer has hours left');
        $this->assertNotEmpty($rows->reject(fn (array $row): bool => $row['hours']['has_retainer']), 'a client has no retainer');
    }

    public function test_the_demo_shows_an_open_backlog_both_kinds_of_closure_and_an_ended_retainer(): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);
        $this->travelTo(Carbon::parse('2026-10-14 08:00:00', 'UTC'));
        $this->seed(DemoSeeder::class);
        $bank = app(\App\Services\Clients\HoursBank::class);
        $client = fn (string $fragment): Client => Client::query()->where('name', 'like', "%{$fragment}%")->sole();

        $this->assertSame('2026-08', User::query()->sole()->account->settlementFrom());

        $workshop = $bank->settlement($client('Pracownia Mebli'));
        $this->assertSame('2026-09', $workshop->activePeriod);
        $this->assertSame('report', $workshop->closureFor('2026-08')?->snapshot['source']);

        $school = $bank->settlement($client('Szkoła Językowa'));
        $this->assertSame('2026-10', $school->activePeriod);
        $this->assertSame('entries', $school->closureFor('2026-09')?->snapshot['source']);

        $clinic = $bank->settlement($client('Klinika Weterynaryjna'));
        $this->assertSame(['2026-08'], $clinic->eligible);
        $this->assertSame('2026-10', $clinic->activePeriod);

        $this->assertSame('2026-08', $bank->activeMonth($client('Biuro Rachunkowe')));
        $this->assertSame(0, \App\Models\ClientReport::query()->where('needs_recalculation', true)->count());
    }

    public function test_the_demo_has_a_private_one_off_client_in_the_order_layout(): void
    {
        $this->seed(DemoSeeder::class);

        $client = Client::query()->where('type', 'individual')->where('cooperation_type', 'one_off')->sole();

        $this->assertSame(ClientBillingMode::ORDER, app(ClientBillingMode::class)->of($client));
        $this->assertSame('Audyt energetyczny domu', $client->generalProject()?->name);
        $this->assertSame(240000, $client->invoices()->sole()->gross_price);
    }

    public function test_demo_reset_refuses_outside_demo_mode(): void
    {
        $this->artisan('demo:reset')->assertFailed();
    }

    public function test_the_demo_password_comes_from_config_so_a_cached_config_keeps_it(): void
    {
        config(['app.demo_password' => 'from-config']);

        $this->seed(DemoSeeder::class);

        $this->assertTrue(Hash::check('from-config', User::query()->sole()->password));
    }
}
