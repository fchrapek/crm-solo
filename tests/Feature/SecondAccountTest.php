<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\MonthCloseStatusTool;
use App\Models\Account;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\MonthCloseRun;
use App\Models\Setting;
use App\Models\User;
use App\Services\Agent\TodayDigest;
use App\Services\Search\GlobalSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

/** A second business on the same instance: its own account, vocabulary and modules, nothing of the operator's. */
final class SecondAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $pilot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = User::factory()->create(['account_id' => Account::factory()->create()->id, 'owner' => true]);
        $this->pilot = User::factory()->create(['account_id' => Account::factory()->create(['settings' => ['features' => ['billing' => false]]])->id, 'owner' => true]);
    }

    public function test_accounts_create_refuses_while_local_verbs_would_lose_their_account(): void
    {
        config(['agent.local_account' => null]);
        $file = tempnam(sys_get_temp_dir(), 'pw');
        file_put_contents($file, 'a-long-enough-secret');

        $this->artisan('accounts:create', ['name' => 'Pilot Three', '--owner-email' => 'three@pilot.test', '--owner-first-name' => 'Ola', '--owner-last-name' => 'Nowak', '--password-file' => $file])
            ->expectsOutputToContain('AGENT_LOCAL_ACCOUNT')
            ->assertFailed();
        unlink($file);
    }

    public function test_accounts_create_makes_an_account_with_its_owner(): void
    {
        config(['agent.local_account' => $this->operator->account_id]);
        $file = tempnam(sys_get_temp_dir(), 'pw');
        file_put_contents($file, "a-long-enough-secret\n");

        $this->artisan('accounts:create', ['name' => 'Pilot Two', '--owner-email' => 'Owner@Pilot.test', '--owner-first-name' => 'Ola', '--owner-last-name' => 'Nowak', '--password-file' => $file, '--off' => ['billing']])
            ->assertSuccessful();

        $account = Account::query()->where('name', 'Pilot Two')->firstOrFail();
        $owner = User::query()->where('email', 'owner@pilot.test')->firstOrFail();
        $this->assertFalse($account->is_test);
        $this->assertFalse($account->hasFeature('billing'));
        $this->assertSame($account->id, $owner->account_id);
        $this->assertTrue((bool) $owner->owner);
        $this->assertTrue(Hash::check('a-long-enough-secret', $owner->password));
        unlink($file);
    }

    public function test_accounts_create_refuses_a_taken_login_and_a_short_password(): void
    {
        config(['agent.local_account' => $this->operator->account_id]);
        $file = tempnam(sys_get_temp_dir(), 'pw');
        file_put_contents($file, 'a-long-enough-secret');
        $args = ['name' => 'Pilot Two', '--owner-first-name' => 'Ola', '--owner-last-name' => 'Nowak', '--password-file' => $file];

        $this->artisan('accounts:create', [...$args, '--owner-email' => $this->operator->email])->assertFailed();

        file_put_contents($file, 'short');
        $this->artisan('accounts:create', [...$args, '--owner-email' => 'new@pilot.test'])->assertFailed();

        $this->assertSame(2, Account::query()->count());
        unlink($file);
    }

    public function test_a_custom_source_belongs_to_the_account_that_added_it(): void
    {
        Setting::create(['account_id' => $this->pilot->account_id, 'scope' => 'leadgen', 'data' => ['custom_sources' => ['flyer']]]);
        $pipeline = Lead::pipelines()[0];

        Lead::create(['account_id' => $this->pilot->account_id, 'pipeline' => $pipeline, 'name' => 'Pilot lead', 'source' => 'flyer']);
        $this->assertContains('flyer', Lead::sources($this->pilot->account_id));
        $this->assertNotContains('flyer', Lead::sources($this->operator->account_id));

        $this->expectException(InvalidArgumentException::class);
        Lead::create(['account_id' => $this->operator->account_id, 'pipeline' => $pipeline, 'name' => 'Operator lead', 'source' => 'flyer']);
    }

    public function test_pipeline_labels_and_source_lists_are_read_per_account(): void
    {
        $pipeline = Lead::pipelines()[0];
        Setting::create(['account_id' => $this->pilot->account_id, 'scope' => 'leadgen', 'data' => ['custom_sources' => ['flyer'], 'pipeline_labels' => [$pipeline => 'Audyty']]]);

        $this->actingAs($this->pilot)->get('/leads')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('pipelines.0.label', 'Audyty')
            ->where('sources', fn ($sources): bool => collect($sources)->contains('flyer')));

        $this->actingAs($this->operator)->get('/leads')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('pipelines.0.label', config("leadgen.pipelines.{$pipeline}.label"))
            ->where('sources', fn ($sources): bool => ! collect($sources)->contains('flyer')));
    }

    public function test_saving_sources_keeps_the_accounts_pipeline_labels(): void
    {
        $pipeline = Lead::pipelines()[0];
        Setting::create(['account_id' => $this->pilot->account_id, 'scope' => 'leadgen', 'data' => ['pipeline_labels' => [$pipeline => 'Audyty']]]);

        $this->actingAs($this->pilot)->patch('/settings/leadgen', ['custom_sources' => ['flyer'], 'source_labels' => []])->assertRedirect();

        $data = Setting::query()->where('account_id', $this->pilot->account_id)->where('scope', 'leadgen')->value('data');
        $this->assertSame(['flyer'], $data['custom_sources']);
        $this->assertSame('Audyty', $data['pipeline_labels'][$pipeline]);
    }

    public function test_billing_screens_are_absent_for_an_account_that_switched_billing_off(): void
    {
        foreach (['/month-close', '/revenue'] as $url) {
            $this->actingAs($this->pilot)->get($url)->assertNotFound();
            $this->actingAs($this->operator)->get($url)->assertOk();
        }

        $this->actingAs($this->pilot)->get('/clients')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.account.features.billing', false));
    }

    public function test_agent_reads_leave_out_billing_data_when_billing_is_off(): void
    {
        $client = Client::factory()->create(['account_id' => $this->pilot->account_id, 'name' => 'Kowalski Dom', 'month_close_type' => 'maintenance']);
        config(['agent.local_account' => $this->pilot->account_id]);

        Artisan::call('crm:brief', ['client' => (string) $client->id, '--json' => true]);
        $brief = json_decode(Artisan::output(), true);
        Artisan::call('client:config', ['client' => (string) $client->id, '--json' => true]);
        $config = json_decode(Artisan::output(), true);

        foreach (['month_close_type', 'billing_mode', 'retainers', 'latest_report'] as $key) {
            $this->assertArrayNotHasKey($key, $brief);
        }
        $this->assertSame(['month', 'month_counts'], array_keys($brief['hours']));
        $this->assertSame(['id', 'name', 'ssh_config'], array_keys($config));
    }

    public function test_month_close_verbs_refuse_when_billing_is_off(): void
    {
        $client = Client::factory()->create(['account_id' => $this->pilot->account_id, 'name' => 'Kowalski Dom', 'month_close_type' => 'maintenance']);
        config(['agent.local_account' => $this->pilot->account_id]);

        Artisan::call('month-close:tick', ['client' => (string) $client->id, 'step' => 'list', '--json' => true]);
        $this->assertStringContainsString('feature_off', Artisan::output());

        CrmServer::tool(MonthCloseStatusTool::class, ['client' => (string) $client->id])
            ->assertHasErrors()
            ->assertSee('feature_off');
    }

    public function test_billing_records_stay_out_of_the_client_tab_today_and_search_with_billing_off(): void
    {
        $client = Client::factory()->create(['account_id' => $this->pilot->account_id, 'name' => 'Kowalski Dom', 'month_close_type' => 'maintenance']);
        MonthCloseRun::create(['account_id' => $this->pilot->account_id, 'client_id' => $client->id, 'period' => '2026-09', 'close_type' => 'maintenance', 'status' => 'open']);
        Invoice::create(['account_id' => $this->pilot->account_id, 'client_id' => $client->id, 'external_id' => 'k-1', 'number' => '1/09/2026', 'status' => 'paid', 'invoice_date' => '2026-09-05', 'gross_price' => 100]);

        $this->actingAs($this->pilot)->get("/clients/{$client->id}/rozliczenia")->assertNotFound();
        $this->actingAs($this->pilot)->get("/clients/{$client->id}/dane")->assertOk();
        $this->assertCount(0, app(TodayDigest::class)->build($this->pilot->account_id)['month_close_open']);
        $this->assertSame([], app(GlobalSearch::class)->search($this->pilot->account_id, '1/09/2026', group: 'invoices')['groups']);

        $this->pilot->account->update(['settings' => []]);
        $this->assertCount(1, app(TodayDigest::class)->build($this->pilot->account_id)['month_close_open']);
        $this->assertNotSame([], app(GlobalSearch::class)->search($this->pilot->account_id, '1/09/2026', group: 'invoices')['groups']);
    }

    public function test_server_configuration_shows_only_to_the_operator_accounts_owner(): void
    {
        $member = User::factory()->create(['account_id' => $this->operator->account_id, 'owner' => false]);
        $this->actingAs($member)->get('/settings/integracje')->assertOk()->assertInertia(fn (Assert $page) => $page->where('server', null));

        $this->actingAs($this->pilot)->get('/settings/integracje')->assertOk()->assertInertia(fn (Assert $page) => $page->where('server', null));
        $this->actingAs($this->operator)->get('/settings/integracje')->assertOk()->assertInertia(fn (Assert $page) => $page->whereType('server', 'array'));
    }
}
