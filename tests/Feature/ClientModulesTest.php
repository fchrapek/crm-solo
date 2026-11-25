<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientRetainer;
use App\Models\MonthCloseRun;
use App\Models\Project;
use App\Models\User;
use App\Services\Clients\Fields\ClientFieldDefinitions;
use App\Services\Clients\MonthCloseWorklist;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** An optional feature is an account module: a switch in Settings, Account, off for a new account, and a client tile only while on. */
final class ClientModulesTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'Acme']);
        $this->owner = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->client = $this->account->clients()->create(['name' => 'Pracownia Testowa', 'currency' => 'PLN', 'month_close_type' => 'maintenance']);
    }

    public function test_a_new_account_starts_with_every_module_off(): void
    {
        $this->assertFalse($this->account->usesModule('month_close'));
        $this->assertFalse($this->account->usesModule('report_baseline'));
        $this->assertFalse($this->account->usesModule('unknown'));

        $this->actingAs($this->owner)->get('/settings/general')->assertInertia(fn (Assert $page) => $page
            ->where('modules.month_close', false)
            ->where('modules.report_baseline', false)
            ->where('modules.client_fields', 0)
            ->where('modules.sms_rules', 0));
    }

    public function test_the_owner_switches_one_module_without_touching_the_other_or_the_accounts_settings(): void
    {
        $this->account->update(['settings' => ['settlement_from' => '2026-01', 'report_baseline' => true]]);

        $this->actingAs($this->owner)->from('/settings/general')->patch('/settings/modules', ['month_close' => true])->assertRedirect('/settings/general');

        $this->assertSame(['settlement_from' => '2026-01', 'report_baseline' => true, 'month_close' => true], $this->account->fresh()->settings);
    }

    public function test_the_month_close_refuses_to_switch_off_while_a_client_is_in_it_and_names_how_many(): void
    {
        $this->account->update(['settings' => ['month_close' => true]]);
        $this->client->update(['include_in_month_close' => true]);
        $this->account->clients()->create(['name' => 'Drugi', 'currency' => 'PLN', 'month_close_type' => 'gig', 'include_in_month_close' => true]);
        $this->account->clients()->create(['name' => 'Typ bez włączenia', 'currency' => 'PLN', 'month_close_type' => 'gig']);

        $this->actingAs($this->owner)->get('/settings/general')->assertInertia(fn (Assert $page) => $page->where('modules.month_close_in_use', 2));
        $this->actingAs($this->owner)->patchJson('/settings/modules', ['month_close' => false])
            ->assertStatus(422)
            ->assertJsonPath('errors.month_close.0', '2 clients are in the month close. Take them out on each client\'s Dane.');

        $this->assertTrue($this->account->fresh()->usesModule('month_close'));
    }

    public function test_an_unfinished_close_run_alone_keeps_the_module_on(): void
    {
        $this->client->update(['month_close_type' => null]);
        $this->account->update(['settings' => ['month_close' => true]]);
        MonthCloseRun::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'period' => '2026-10', 'status' => MonthCloseRun::STATUS_OPEN]);

        $this->assertSame(1, app(MonthCloseWorklist::class)->heldCount($this->account));
        $this->actingAs($this->owner)->patchJson('/settings/modules', ['month_close' => false])->assertStatus(422);
    }

    public function test_a_client_still_owing_an_hours_month_keeps_the_module_on_as_the_worklist_lists_it(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'UTC'));
        $this->account->update(['settings' => ['month_close' => true, 'settlement_from' => '2026-09']]);
        $this->client->update(['month_close_type' => null]);
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'monthly_hours' => 10, 'effective_from' => '2026-01-01', 'currency' => 'PLN']);

        $listed = collect(app(MonthCloseWorklist::class)->rows($this->account->fresh(), '2026-09'))->pluck('client.id')->all();

        $this->assertSame([$this->client->id], $listed);
        $this->assertSame(1, app(MonthCloseWorklist::class)->heldCount($this->account->fresh()));
        $this->actingAs($this->owner)->patchJson('/settings/modules', ['month_close' => false])->assertStatus(422);
        $this->assertTrue($this->account->fresh()->usesModule('month_close'));
    }

    public function test_with_the_module_off_a_client_owing_an_hours_month_still_shows_its_month_close(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'UTC'));
        $this->account->update(['settings' => ['settlement_from' => '2026-09']]);
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'monthly_hours' => 10, 'effective_from' => '2026-01-01', 'currency' => 'PLN']);
        $outside = $this->account->clients()->create(['name' => 'Bez zamknięcia', 'currency' => 'PLN', 'month_close_type' => 'gig']);

        $this->assertFalse($this->account->fresh()->usesModule('month_close'));
        $this->actingAs($this->owner)->get("/clients/{$this->client->id}/details")->assertInertia(fn (Assert $page) => $page->where('details.modules.month_close', true));
        $this->actingAs($this->owner)->get("/clients/{$outside->id}/details")->assertInertia(fn (Assert $page) => $page->where('details.modules.month_close', false));
    }

    public function test_enrolling_a_client_while_the_module_is_off_turns_it_on_and_says_so(): void
    {
        $other = $this->account->clients()->create(['name' => 'Nowy', 'currency' => 'PLN']);

        $this->actingAs($this->owner)->from("/clients/{$other->id}/details")->patch("/clients/{$other->id}/month-close-type", ['month_close_type' => 'maintenance'])
            ->assertRedirect("/clients/{$other->id}/details")
            ->assertSessionHas('success', 'The month close module is on again: a client in the close needs it.');

        $this->assertTrue($this->account->fresh()->usesModule('month_close'));
    }

    public function test_a_stale_enrolment_form_after_the_module_went_off_turns_it_back_on(): void
    {
        $this->client->update(['month_close_type' => null]);
        $this->account->update(['settings' => ['month_close' => true]]);
        $this->actingAs($this->owner)->patch('/settings/modules', ['month_close' => false])->assertSessionHasNoErrors();
        $this->assertFalse($this->account->fresh()->usesModule('month_close'));

        $this->actingAs($this->owner)->patch("/clients/{$this->client->id}/month-close-type", ['month_close_type' => 'gig']);

        $this->assertTrue($this->account->fresh()->usesModule('month_close'));
    }

    public function test_a_tick_that_starts_a_run_with_the_module_off_keeps_the_clients_month_close_in_sight(): void
    {
        $this->assertSame(0, Artisan::call('month-close:tick', ['client' => (string) $this->client->id, 'step' => 'report', 'state' => 'done', '--period' => '2026-10']));

        $this->assertFalse($this->account->fresh()->usesModule('month_close'));
        $this->assertSame(1, app(MonthCloseWorklist::class)->heldCount($this->account->fresh()));
        $this->actingAs($this->owner)->get("/clients/{$this->client->id}/details")->assertInertia(fn (Assert $page) => $page->where('details.modules.month_close', true));
    }

    public function test_a_client_restored_into_the_close_with_the_module_off_shows_its_month_close(): void
    {
        $this->client->update(['include_in_month_close' => true]);
        $this->client->delete();
        $this->assertSame(0, app(MonthCloseWorklist::class)->heldCount($this->account->fresh()));

        $this->actingAs($this->owner)->put("/clients/{$this->client->id}/restore");

        $this->assertFalse($this->account->fresh()->usesModule('month_close'));
        $this->actingAs($this->owner)->get("/clients/{$this->client->id}/details")->assertInertia(fn (Assert $page) => $page->where('details.modules.month_close', true));
    }

    public function test_an_edit_that_leaves_the_client_out_of_the_close_keeps_the_module_off(): void
    {
        $this->actingAs($this->owner)->put("/clients/{$this->client->id}", ['type' => 'business', 'name' => 'Pracownia Testowa', 'include_in_month_close' => false])
            ->assertSessionHas('success', 'Client updated.');

        $this->assertFalse($this->account->fresh()->usesModule('month_close'));
    }

    public function test_the_month_close_switches_off_once_no_client_is_in_it(): void
    {
        $this->client->update(['month_close_type' => null]);
        $this->account->update(['settings' => ['month_close' => true]]);
        MonthCloseRun::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'period' => '2026-09', 'status' => MonthCloseRun::STATUS_COMPLETED]);

        $this->actingAs($this->owner)->from('/settings/general')->patch('/settings/modules', ['month_close' => false])->assertRedirect('/settings/general')->assertSessionHasNoErrors();

        $this->assertFalse($this->account->fresh()->usesModule('month_close'));
    }

    public function test_a_member_has_no_modules_and_cannot_switch_one(): void
    {
        $member = User::factory()->create(['account_id' => $this->account->id, 'owner' => false]);

        $this->actingAs($member)->get('/settings/general')->assertNotFound();
        $this->actingAs($member)->patch('/settings/modules', ['month_close' => true])->assertForbidden();
        $this->assertFalse($this->account->fresh()->usesModule('month_close'));
    }

    public function test_the_modules_count_the_accounts_fields(): void
    {
        app(ClientFieldDefinitions::class)->define($this->account->id, 'note', ['label' => 'Uwagi', 'type' => 'text']);

        $this->actingAs($this->owner)->get('/settings/general')->assertInertia(fn (Assert $page) => $page->where('modules.client_fields', 1));
    }

    public function test_details_tells_which_modules_are_on_and_whether_the_client_has_a_baseline(): void
    {
        $this->actingAs($this->owner)->get("/clients/{$this->client->id}/details")->assertInertia(fn (Assert $page) => $page
            ->where('details.modules', ['month_close' => false, 'report_baseline' => false])
            ->where('details.has_report_baseline', false));

        $this->account->update(['settings' => ['month_close' => true, 'report_baseline' => true]]);
        $this->client->update(['report_baseline_markdown' => '- monitoring']);

        $this->actingAs($this->owner)->get("/clients/{$this->client->id}/details")->assertInertia(fn (Assert $page) => $page
            ->where('details.modules', ['month_close' => true, 'report_baseline' => true])
            ->where('details.has_report_baseline', true));
    }

    public function test_tasks_lists_what_stands_behind_each_project_and_the_close_only_while_its_module_is_on(): void
    {
        Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Strona', 'include_in_month_close' => true]);
        $strona = fn (): array => collect($this->actingAs($this->owner)->get("/clients/{$this->client->id}/tasks")->viewData('page')['props']['tasks']['projects'])->firstWhere('name', 'Strona');

        $this->assertSame(['is_private' => true, 'in_month_close' => false, 'repositories' => 0], array_intersect_key($strona(), array_flip(['is_private', 'in_month_close', 'repositories'])));

        $this->account->update(['settings' => ['month_close' => true]]);

        $this->assertTrue($strona()['in_month_close']);
    }

    public function test_the_migration_turns_the_month_close_on_only_for_accounts_with_a_client_in_it_or_a_close_run(): void
    {
        $quiet = Account::create(['name' => 'Bez zamknięcia']);
        $quiet->clients()->create(['name' => 'Klient', 'currency' => 'PLN']);
        $ran = Account::create(['name' => 'Po zamknięciu']);
        $past = $ran->clients()->create(['name' => 'Dawny', 'currency' => 'PLN']);
        MonthCloseRun::create(['account_id' => $ran->id, 'client_id' => $past->id, 'period' => '2026-09', 'status' => MonthCloseRun::STATUS_COMPLETED]);
        DB::table('accounts')->where('id', $this->account->id)->update(['settings' => json_encode(['settlement_from' => '2026-01'])]);

        $migration = require database_path('migrations/2026_11_21_210000_turn_on_month_close_where_a_client_uses_it.php');
        $migration->up();

        $this->assertTrue($this->account->fresh()->usesModule('month_close'));
        $this->assertSame('2026-01', $this->account->fresh()->settings['settlement_from']);
        $this->assertFalse($quiet->fresh()->usesModule('month_close'));
        $this->assertTrue($ran->fresh()->usesModule('month_close'));

        $migration->down();
        $this->assertSame(['settlement_from' => '2026-01'], $this->account->fresh()->settings);
    }

    public function test_the_demo_account_has_both_modules_on(): void
    {
        // The seeder writes its sample attachments to the disk.
        Storage::fake('local');
        $this->seed(DemoSeeder::class);

        $demo = User::query()->where('email', User::DEMO_EMAIL)->sole()->account;

        $this->assertTrue($demo->usesModule('month_close'));
        $this->assertTrue($demo->usesModule('report_baseline'));
    }
}
