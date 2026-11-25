<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\InfaktDraftRequest;
use App\Models\Integration;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\AgentAbilities;
use App\Services\Integrations\InfaktDrafting;
use App\Services\Integrations\InfaktService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Feature\Concerns\FakesInfaktInvoices;
use Tests\TestCase;

/**
 * Wystaw podobna per invoice group: each group copies the invoice recorded for it in the latest earlier month, whole
 * (Dodatkowy podmiot, notes, bank, line extras), minus what would issue, number or pay it.
 */
final class InfaktInvoiceCopyTest extends TestCase
{
    use FakesInfaktInvoices;
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private Client $client;

    private Project $eat;

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 10:00:00');
        Sleep::fake();
        $this->account = Account::create(['name' => 'Studio']);
        $this->owner = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        Integration::create(['account_id' => $this->account->id, 'provider' => 'infakt', 'api_key' => 'test-key', 'is_enabled' => true]);
        $this->client = Client::factory()->create([
            'account_id' => $this->account->id, 'name' => 'Polska Example', 'month_close_type' => 'maintenance',
            'include_in_month_close' => true, 'external_ids' => ['infakt' => '10000002'],
        ]);
        $this->eat = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'sklepdemo']);
        $this->site = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'tutaj site']);
        foreach ([[$this->eat, 1, 2500], [$this->site, 2, 1500]] as [$project, $group, $fee]) {
            $this->client->retainers()->create([
                'account_id' => $this->account->id, 'project_id' => $project->id, 'invoice_group' => $group, 'monthly_hours' => 5,
                'monthly_fee' => $fee, 'currency' => 'PLN', 'effective_from' => '2025-01-01',
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_copy_is_the_source_minus_the_forbidden_and_replaced_fields(): void
    {
        $source = $this->fixture();

        $invoice = $this->service()->copyInvoicePayload($source, '10000002', Carbon::parse('2026-11-01'))['invoice'];

        $expected = collect($source)->except([...InfaktService::DRAFT_FORBIDDEN_FIELDS, ...InfaktService::DRAFT_REPLACED_FIELDS])
            ->reject(fn (mixed $value): bool => $value === null || $value === '' || $value === [])->keys()
            ->merge(['client_id', 'invoice_date', 'payment_date', 'sale_date'])->sort()->values()->all();
        $this->assertSame($expected, collect(array_keys($invoice))->sort()->values()->all());
        $this->assertSame(['client_id' => 10000002, 'sale_date' => '2026-11-30', 'invoice_date' => '2026-11-03', 'payment_date' => '2026-11-17'], array_intersect_key($invoice, array_flip(['client_id', 'sale_date', 'invoice_date', 'payment_date'])));
        $this->assertSame([[
            'company_name' => 'Centrum Usług Wspólnych w Przykładowie', 'tax_id' => '0000000001', 'street' => 'Szkolna', 'building_number' => '5',
            'post_code' => '00-002', 'city' => 'Przykładowo', 'country_code' => 'PL', 'role_code' => '2',
        ]], $invoice['third_party_addresses']);
        $this->assertSame(['notes', 'split_payment', 'split_payment_type', 'recipient_signature', 'seller_signature', 'bank_account', 'bank_name', 'transaction_terms'], array_values(array_intersect(['notes', 'split_payment', 'split_payment_type', 'recipient_signature', 'seller_signature', 'bank_account', 'bank_name', 'transaction_terms'], array_keys($invoice))));
        $this->assertSame([
            ['name' => $source['services'][0]['name'], 'tax_symbol' => '23', 'unit' => 'usł.', 'quantity' => 1, 'unit_net_price' => 250000, 'pkwiu' => '62.02.30', 'discount' => '0.0', 'unit_net_price_before_discount' => 250000, 'gtu_id' => 12],
            ['name' => 'Hosting i kopie zapasowe', 'tax_symbol' => '23', 'unit' => 'mies.', 'quantity' => 2, 'unit_net_price' => 25000, 'discount' => '50.0', 'unit_net_price_before_discount' => 50000],
        ], $invoice['services']);
        $this->assertArrayNotHasKey('client_company_name', $invoice);
        InfaktService::assertDraftOnly(['invoice' => $invoice]);
    }

    public function test_a_fractional_quantity_is_kept_and_a_whole_one_is_sent_as_an_integer(): void
    {
        $source = $this->fixture();
        $source['services'][0]['quantity'] = 1.5;

        $lines = $this->service()->copyInvoicePayload($source, '10000002', Carbon::parse('2026-11-01'))['invoice']['services'];

        $this->assertSame([1.5, 2], array_column($lines, 'quantity'));
    }

    public function test_a_source_due_before_or_on_its_issue_day_by_transfer_is_refused(): void
    {
        foreach (['2026-10-30', '2026-10-31'] as $due) {
            try {
                $this->service()->copyInvoicePayload([...$this->fixture(), 'payment_date' => $due], '10000002', Carbon::parse('2026-11-01'));
                $this->fail("A term ending {$due} passed.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('is due', $e->getMessage());
            }
        }
        $cash = $this->service()->copyInvoicePayload([...$this->fixture(), 'payment_method' => 'cash', 'payment_date' => '2026-10-31'], '10000002', Carbon::parse('2026-11-01'));
        $this->assertSame('2026-11-03', $cash['invoice']['payment_date']);
    }

    public function test_two_chained_groups_give_two_whole_copies_and_a_rerun_none(): void
    {
        $this->chainOctober();

        $this->verb(['Polska', '--period=2026-11', '--like=last', '--json'])->assertHeader('X-Crm-Exit', '0');
        $rerun = $this->verb(['Polska', '--period=2026-11', '--like=last', '--json'])->assertHeader('X-Crm-Exit', '0');

        $this->assertSame(['already_drafted', 'already_drafted'], array_column($rerun->json('results'), 'outcome'));
        $posts = $this->infaktPosts();
        $this->assertCount(2, $posts);
        $this->assertSame([['Centrum Usług Wspólnych w Przykładowie'], []], $posts->map(fn ($p): array => array_column($p->data()['invoice']['third_party_addresses'] ?? [], 'company_name'))->all());
        $this->assertSame(
            [[1, 'copy', 'cuw-1510', '15/10/2026', [$this->eat->id]], [2, 'copy', 'site-1610', '16/10/2026', [$this->site->id]]],
            InfaktDraftRequest::query()->where('period', '2026-11')->orderBy('invoice_group')->get()
                ->map(fn (InfaktDraftRequest $r): array => [$r->invoice_group, $r->source, $r->source_invoice_uuid, $r->source_invoice_number, $r->project_ids])->all(),
        );
        $this->assertSame(['sklepdemo', 'tutaj site'], array_map(fn (array $r): ?string => $r['project'][0]['name'], $rerun->json('results')));
    }

    public function test_no_posted_draft_carries_a_forbidden_field_at_any_depth(): void
    {
        $this->chainOctober();

        $this->verb(['Polska', '--period=2026-11', '--like=last', '--json'])->assertHeader('X-Crm-Exit', '0');

        $keys = function (array $fields) use (&$keys): array {
            $found = [];
            foreach ($fields as $key => $value) {
                $found[] = (string) $key;
                if (is_array($value) && $key !== 'transaction_terms') {
                    $found = [...$found, ...$keys($value)];
                }
            }

            return $found;
        };
        foreach ($this->infaktPosts() as $post) {
            $this->assertSame('https://api.infakt.pl/v3/async/invoices.json', $post->url());
            $this->assertSame([], array_values(array_intersect($keys($post->data()), InfaktService::DRAFT_FORBIDDEN_FIELDS)));
        }
    }

    public function test_last_with_two_groups_and_nothing_recorded_needs_a_source_for_each_and_sends_nothing(): void
    {
        $this->fakeInfaktInvoices([$this->issuedInvoice('cuw-1510', '15/10/2026', 250000, 'Utrzymanie sklepdemo', ['invoice_date' => '2026-10-31'])]);

        $response = $this->verb(['Polska', '--period=2026-11', '--like=last', '--json'])->assertHeader('X-Crm-Exit', '1');

        $this->assertSame(['needs_source', 'needs_source'], array_column($response->json('results'), 'outcome'));
        $this->assertCount(0, $this->infaktPosts());
    }

    public function test_a_pick_is_recorded_and_the_next_month_follows_it_never_the_newer_project_invoice(): void
    {
        $this->client->retainers()->where('project_id', $this->site->id)->delete();
        $this->fakeInfaktInvoices([
            $this->issuedInvoice('eat-0410', '4/10/2026', 250000, 'Utrzymanie sklepdemo', ['invoice_date' => '2026-10-04', 'sale_date' => '2026-09-30']),
            $this->issuedInvoice('big-2010', '20/10/2026', 1000000, 'Projekt nowej strony', ['invoice_date' => '2026-10-20']),
        ]);

        $this->verb(['Polska', '--period=2026-10', '--project=sklepdemo', '--like=4/10/2026', '--json'])->assertHeader('X-Crm-Exit', '0');
        $this->infaktInvoices['draft-ref-3'] = [...$this->infaktInvoices['draft-ref-3'], 'status' => 'sent', 'number' => '1/11/2026', 'invoice_date' => '2026-11-03'];
        $this->verb(['Polska', '--period=2026-11', '--like=last', '--json'])->assertHeader('X-Crm-Exit', '0');

        $rows = InfaktDraftRequest::query()->orderBy('period')->get();
        $this->assertSame([['2026-10', 'eat-0410', '4/10/2026'], ['2026-11', 'draft-ref-3', '1/11/2026']], $rows->map(fn ($r): array => [$r->period, $r->source_invoice_uuid, $r->source_invoice_number])->all());
        $this->assertSame([250000, 250000], $this->infaktPosts()->map(fn ($p): int => $p->data()['invoice']['services'][0]['unit_net_price'])->all());
    }

    public function test_an_invoice_number_without_a_project_is_refused_when_there_are_two_groups(): void
    {
        $this->fakeInfaktInvoices([$this->issuedInvoice('eat-0410', '4/10/2026', 250000, 'Utrzymanie sklepdemo')]);

        $this->verb(['Polska', '--period=2026-10', '--like=4/10/2026', '--json'])->assertHeader('X-Crm-Exit', '1')->assertSee('name the project');
        $this->assertCount(0, $this->infaktPosts());
    }

    public function test_a_correction_advance_or_draft_source_needs_a_source_while_the_other_group_drafts(): void
    {
        foreach ([['kind' => 'correction'], ['number' => '1/ZAL/10/2026'], ['status' => 'draft']] as $i => $bad) {
            InfaktDraftRequest::query()->delete();
            $this->infaktInvoices = [];
            $this->chainOctober(['site-1610' => $bad]);

            $response = $this->verb(['Polska', '--period=2026-11', '--like=last', '--json'])->assertHeader('X-Crm-Exit', '1');

            $this->assertSame(['created', 'needs_source'], array_column($response->json('results'), 'outcome'), "case {$i}");
            $this->assertSame(1, InfaktDraftRequest::query()->where('period', '2026-11')->count());
        }
    }

    public function test_a_group_whose_projects_changed_needs_a_new_pick(): void
    {
        $this->chainOctober();
        $other = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'blog']);
        $this->client->retainers()->create(['account_id' => $this->account->id, 'project_id' => $other->id, 'invoice_group' => 2, 'monthly_hours' => 1, 'monthly_fee' => 100, 'currency' => 'PLN', 'effective_from' => '2026-11-01']);

        $response = $this->verb(['Polska', '--period=2026-11', '--like=last', '--json']);

        $this->assertSame(['created', 'needs_source'], array_column($response->json('results'), 'outcome'));
        $this->assertStringContainsString('projects of this invoice group changed', (string) $response->json('results.1.message'));
    }

    public function test_a_month_holding_a_copy_recorded_without_a_group_is_not_drafted_again(): void
    {
        $this->chainOctober();
        InfaktDraftRequest::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'period' => '2026-11', 'invoice_group' => 0, 'source' => 'copy', 'status' => InfaktDraftRequest::STATUS_CREATED, 'attempt' => 1, 'invoice_uuid' => 'old-copy']);

        $this->verb(['Polska', '--period=2026-11', '--json'])->assertHeader('X-Crm-Exit', '1')->assertSee('before drafts were kept per project');
        $this->assertCount(0, $this->infaktPosts());
    }

    public function test_a_mixed_month_copies_one_project_and_drafts_the_other_from_the_retainer(): void
    {
        $this->chainOctober();

        $this->verb(['Polska', '--period=2026-11', '--project=sklepdemo', '--like=last', '--json'])->assertHeader('X-Crm-Exit', '0');
        $this->verb(['Polska', '--period=2026-11', '--json'])->assertHeader('X-Crm-Exit', '0')->assertSee('already_drafted');

        $this->assertSame([[1, 'copy'], [2, 'retainer']], InfaktDraftRequest::query()->where('period', '2026-11')->orderBy('invoice_group')->get()->map(fn ($r): array => [$r->invoice_group, $r->source])->all());
        $this->assertSame([250000, 150000], $this->infaktPosts()->map(fn ($p): int => $p->data()['invoice']['services'][0]['unit_net_price'])->all());
    }

    public function test_a_draft_deleted_in_infakt_is_forgotten_and_its_month_can_be_drafted_again(): void
    {
        $this->chainOctober();
        $draft = ['Polska', '--period=2026-11', '--project=sklepdemo', '--like=last', '--json'];
        $this->verb($draft)->assertHeader('X-Crm-Exit', '0');
        $uuid = (string) InfaktDraftRequest::query()->where('period', '2026-11')->sole()->invoice_uuid;

        $this->artisan('infakt:sync-invoices')->assertSuccessful();
        $this->assertSame(1, Invoice::query()->where('external_uuid', $uuid)->count(), 'a draft Infakt still holds stays');
        $this->assertSame(1, InfaktDraftRequest::query()->where('period', '2026-11')->count());

        unset($this->infaktInvoices[$uuid]);
        $this->artisan('infakt:sync-invoices')->assertSuccessful();

        $this->assertSame(0, Invoice::query()->where('external_uuid', $uuid)->count());
        $this->assertSame(0, InfaktDraftRequest::query()->where('period', '2026-11')->count(), 'the month reads as never drafted, so the first-run checks apply again');

        $this->verb($draft)->assertHeader('X-Crm-Exit', '0');
        $this->assertSame(InfaktDraftRequest::STATUS_CREATED, InfaktDraftRequest::query()->where('period', '2026-11')->sole()->status);
        $this->assertCount(2, $this->infaktPosts());
    }

    public function test_a_deleted_draft_whose_mirror_never_arrived_is_forgotten_too(): void
    {
        $this->chainOctober();
        $this->verb(['Polska', '--period=2026-11', '--project=sklepdemo', '--like=last', '--json'])->assertHeader('X-Crm-Exit', '0');
        $this->artisan('infakt:sync-invoices')->assertSuccessful();
        $uuid = (string) InfaktDraftRequest::query()->where('period', '2026-11')->sole()->invoice_uuid;
        Invoice::query()->where('external_uuid', $uuid)->delete();
        unset($this->infaktInvoices[$uuid]);

        $this->artisan('infakt:sync-invoices')->assertSuccessful();

        $this->assertSame(0, InfaktDraftRequest::query()->where('period', '2026-11')->count());
    }

    public function test_no_draft_is_forgotten_when_the_key_cannot_read_the_invoices_the_account_already_knows(): void
    {
        $this->chainOctober();
        $this->verb(['Polska', '--period=2026-11', '--project=sklepdemo', '--like=last', '--json'])->assertHeader('X-Crm-Exit', '0');
        $this->artisan('infakt:sync-invoices')->assertSuccessful();
        $uuid = (string) InfaktDraftRequest::query()->where('period', '2026-11')->sole()->invoice_uuid;

        // Another company's key: it lists its own invoices and answers 404 for every id this account holds.
        $this->infaktInvoices = [];
        $this->artisan('infakt:sync-invoices')->assertSuccessful();

        $this->assertSame(1, Invoice::query()->where('external_uuid', $uuid)->count());
        $this->assertSame(InfaktDraftRequest::STATUS_CREATED, InfaktDraftRequest::query()->where('period', '2026-11')->sole()->status);
    }

    public function test_a_created_draft_lands_in_the_mirror_with_its_uuid_so_rows_join(): void
    {
        $this->chainOctober();

        $this->verb(['Polska', '--period=2026-11', '--project=sklepdemo', '--like=last', '--json'])->assertHeader('X-Crm-Exit', '0');

        $row = InfaktDraftRequest::query()->where('period', '2026-11')->sole();
        $mirrored = Invoice::query()->where('external_uuid', $row->invoice_uuid)->sole();
        $this->assertSame(['draft', $this->client->id, 300000], [$mirrored->status, $mirrored->client_id, $mirrored->net_price]);
    }

    public function test_the_mcp_tool_answers_one_entry_per_group_with_project_source_and_outcome(): void
    {
        $this->chainOctober();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'invoice_draft', 'arguments' => ['client' => 'Polska', 'period' => '2026-11', 'like' => 'last', 'dry_run' => true]],
        ])->assertOk();

        $payload = json_decode((string) $response->json('result.content.0.text'), true);
        $this->assertSame(['client_id', 'dry_run', 'period', 'mode', 'results'], array_keys($payload));
        $this->assertSame(['group', 'project', 'source', 'outcome', 'payload', 'task_reference', 'message'], array_keys($payload['results'][0]));
        $this->assertSame([[1, 'sklepdemo', '15/10/2026', 'preview'], [2, 'tutaj site', '16/10/2026', 'preview']], array_map(
            fn (array $r): array => [$r['group'], $r['project'][0]['name'], $r['source']['invoice_number'], $r['outcome']],
            $payload['results'],
        ));
        $this->assertCount(0, $this->infaktPosts());
    }

    public function test_the_backfill_moves_a_group_zero_copy_only_when_its_group_is_provable(): void
    {
        $migration = require database_path('migrations/2026_11_03_210000_add_source_to_infakt_draft_requests_table.php');
        $migration->down();
        $single = Client::factory()->create(['account_id' => $this->account->id, 'month_close_type' => 'maintenance']);
        $single->retainers()->create(['account_id' => $this->account->id, 'project_id' => $this->eat->id, 'invoice_group' => 1, 'monthly_hours' => 1, 'monthly_fee' => 100, 'currency' => 'PLN', 'effective_from' => '2025-01-01']);
        $collides = Client::factory()->create(['account_id' => $this->account->id, 'month_close_type' => 'maintenance']);
        $collides->retainers()->create(['account_id' => $this->account->id, 'invoice_group' => 1, 'monthly_hours' => 1, 'monthly_fee' => 100, 'currency' => 'PLN', 'effective_from' => '2025-01-01']);
        $row = fn (Client $c, int $group, string $status): int => DB::table('infakt_draft_requests')->insertGetId([
            'account_id' => $this->account->id, 'client_id' => $c->id, 'period' => '2026-10', 'invoice_group' => $group, 'status' => $status, 'attempt' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $moves = $row($single, 0, 'created');
        $twoGroups = $row($this->client, 0, 'created');
        $blocked = $row($collides, 0, 'created');
        $failed = $row($collides, 1, 'failed');

        $migration->up();

        $state = fn (int $id): array => (array) DB::table('infakt_draft_requests')->where('id', $id)->first(['invoice_group', 'source', 'project_ids']);
        $this->assertSame(['invoice_group' => 1, 'source' => 'copy', 'project_ids' => json_encode([$this->eat->id])], $state($moves));
        $this->assertSame(['invoice_group' => 0, 'source' => 'copy', 'project_ids' => null], $state($twoGroups));
        $this->assertSame(['invoice_group' => 0, 'source' => 'copy', 'project_ids' => null], $state($blocked));
        $this->assertSame(['invoice_group' => 1, 'source' => 'retainer', 'project_ids' => '[]'], $state($failed));
        $this->assertSame(4, DB::table('infakt_draft_requests')->count());
    }

    public function test_billing_shows_each_invoice_group_with_what_it_would_copy(): void
    {
        Carbon::setTestNow('2026-12-02 10:00:00');
        $this->chainOctober();
        InfaktDraftRequest::query()->where('invoice_group', 2)->delete();
        Invoice::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'external_id' => '20000501', 'external_uuid' => 'cuw-1510', 'number' => '15/10/2026', 'status' => 'sent', 'net_price' => 300000, 'sale_date' => '2026-10-31']);

        $this->actingAs($this->owner)->get("/clients/{$this->client->id}/billing")->assertInertia(fn (Assert $page) => $page
            ->where('billing.invoice_drafts.period', '2026-11')
            ->where('billing.invoice_drafts.groups.0', [
                'group' => 1, 'projects' => ['sklepdemo'], 'state' => 'ready', 'net' => 300000, 'currency' => 'PLN', 'chained' => true, 'reason' => null,
                'source' => ['number' => '15/10/2026', 'sale_date' => '2026-10-31'], 'draft_url' => null,
            ])
            ->where('billing.invoice_drafts.groups.1.state', 'needs_source')
            ->where('billing.invoice_drafts.groups.1.net', null));
        $this->assertCount(0, Http::recorded());
    }

    public function test_copy_all_drafts_what_it_can_and_names_the_group_that_needs_a_pick(): void
    {
        Carbon::setTestNow('2026-12-02 10:00:00');
        $this->chainOctober();
        InfaktDraftRequest::query()->where('invoice_group', 2)->delete();

        $this->actingAs($this->owner)->from("/clients/{$this->client->id}/billing")
            ->post("/clients/{$this->client->id}/invoice-drafts", ['period' => '2026-11', 'like' => 'last'])
            ->assertRedirect("/clients/{$this->client->id}/billing")
            ->assertSessionHas('error', 'Drafted in Infakt: sklepdemo. Pick the invoice to copy for: tutaj site.');

        $this->assertSame([1], InfaktDraftRequest::query()->where('period', '2026-11')->pluck('invoice_group')->all());
    }

    public function test_a_pick_from_the_picker_drafts_that_group_and_says_nothing_was_sent(): void
    {
        Carbon::setTestNow('2026-12-02 10:00:00');
        $this->fakeInfaktInvoices([
            $this->issuedInvoice('site-1610', '16/10/2026', 150000, 'Utrzymanie tutaj site', ['invoice_date' => '2026-10-31', 'payment_date' => '2026-11-14']),
            $this->issuedInvoice('kor-1', 'KOR/1/10/2026', 100, 'Korekta', ['invoice_date' => '2026-11-01', 'kind' => 'correction']),
            $this->issuedInvoice('draft-x', '', 100, 'Szkic', ['invoice_date' => '2026-11-02', 'status' => 'draft']),
        ]);

        $listed = $this->actingAs($this->owner)->getJson("/clients/{$this->client->id}/invoice-drafts/sources")->assertOk();
        $this->assertSame(['16/10/2026'], array_column($listed->json('invoices'), 'number'));

        $this->from("/clients/{$this->client->id}/billing")
            ->post("/clients/{$this->client->id}/invoice-drafts", ['period' => '2026-11', 'group' => 2, 'like' => '16/10/2026'])
            ->assertSessionHas('success', 'The invoice draft for November 2026 is in Infakt. Nothing was sent.');

        $row = InfaktDraftRequest::query()->sole();
        $this->assertSame([2, 'copy', '16/10/2026', [$this->site->id]], [$row->invoice_group, $row->source, $row->source_invoice_number, $row->project_ids]);
        $this->assertCount(1, $this->infaktPosts());
    }

    public function test_another_accounts_client_cannot_be_drafted_or_read(): void
    {
        $other = User::factory()->create(['account_id' => Account::create(['name' => 'Other'])->id, 'owner' => true]);
        Http::preventStrayRequests();
        Http::fake();

        $this->actingAs($other)->getJson("/clients/{$this->client->id}/invoice-drafts/sources")->assertNotFound();
        $this->actingAs($other)->post("/clients/{$this->client->id}/invoice-drafts", ['period' => '2026-10', 'like' => 'last'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_an_unresolved_group_zero_copy_blocks_new_sends_and_failed_retries_for_its_month(): void
    {
        $this->chainOctober();
        foreach ([[0, InfaktDraftRequest::STATUS_CREATED], [1, InfaktDraftRequest::STATUS_FAILED]] as [$group, $status]) {
            InfaktDraftRequest::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'period' => '2026-11', 'invoice_group' => $group, 'source' => 'copy', 'status' => $status, 'attempt' => 1]);
        }

        $copy = $this->verb(['Polska', '--period=2026-11', '--like=last', '--json'])->assertHeader('X-Crm-Exit', '1');
        $this->verb(['Polska', '--period=2026-11', '--json'])->assertHeader('X-Crm-Exit', '1');

        $this->assertSame(['blocked', 'blocked'], array_column($copy->json('results'), 'outcome'));
        $this->assertCount(0, $this->infaktPosts());
    }

    public function test_the_block_shows_no_amount_for_a_source_it_has_not_mirrored_and_a_pick_for_an_unusable_one(): void
    {
        Carbon::setTestNow('2026-12-02 10:00:00');
        $this->chainOctober();
        Invoice::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'external_id' => '20000502', 'external_uuid' => 'site-1610', 'number' => '16/10/2026', 'status' => 'draft', 'net_price' => 150000]);

        $this->actingAs($this->owner)->get("/clients/{$this->client->id}/billing")->assertInertia(fn (Assert $page) => $page
            ->where('billing.invoice_drafts.groups.0.state', 'ready')
            ->where('billing.invoice_drafts.groups.0.net', null)
            ->where('billing.invoice_drafts.groups.1.state', 'needs_source')
            ->where('billing.invoice_drafts.groups.1.net', null));
    }

    public function test_a_single_group_client_with_nothing_recorded_picks_once_in_the_app_and_last_stays_for_agents(): void
    {
        Carbon::setTestNow('2026-12-02 10:00:00');
        $this->client->retainers()->where('project_id', $this->site->id)->delete();
        $this->fakeInfaktInvoices([$this->issuedInvoice('eat-1510', '15/10/2026', 250000, 'Utrzymanie sklepdemo', ['invoice_date' => '2026-10-31', 'payment_date' => '2026-11-14'])]);

        $this->actingAs($this->owner)->get("/clients/{$this->client->id}/billing")
            ->assertInertia(fn (Assert $page) => $page->where('billing.invoice_drafts.groups.0.state', 'needs_source'));
        $this->from("/clients/{$this->client->id}/billing")->post("/clients/{$this->client->id}/invoice-drafts", ['period' => '2026-11', 'like' => 'last'])
            ->assertSessionHas('error', 'Pick the invoice to copy for: sklepdemo.');
        $this->assertCount(0, $this->infaktPosts());

        $this->verb(['Polska', '--period=2026-11', '--like=last', '--dry-run', '--json'])->assertHeader('X-Crm-Exit', '0')->assertSee('"invoice_number":"15/10/2026"', false);
    }

    public function test_on_the_demo_the_block_is_absent_and_a_submitted_draft_is_never_looked_up(): void
    {
        Carbon::setTestNow('2026-12-02 10:00:00');
        InfaktDraftRequest::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'period' => '2026-11', 'invoice_group' => 1, 'source' => 'copy', 'project_ids' => [$this->eat->id], 'status' => InfaktDraftRequest::STATUS_SUBMITTED, 'attempt' => 1, 'task_reference' => 'ref-9']);
        config(['app.demo' => true]);
        Http::fake();

        $this->actingAs($this->owner)->get("/clients/{$this->client->id}/billing")
            ->assertInertia(fn (Assert $page) => $page->where('billing.invoice_drafts', null));

        Http::assertNothingSent();
        $this->assertSame(InfaktDraftRequest::STATUS_SUBMITTED, InfaktDraftRequest::query()->sole()->status);
    }

    public function test_a_draft_infakt_was_still_building_is_confirmed_and_mirrored_when_the_block_loads(): void
    {
        Carbon::setTestNow('2026-12-02 10:00:00');
        $this->chainOctober();
        $this->infaktInvoices['draft-ref-9'] = $this->issuedInvoice('draft-ref-9', '', 250000, 'Utrzymanie sklepdemo', ['status' => 'draft', 'number' => null]);
        InfaktDraftRequest::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'period' => '2026-11', 'invoice_group' => 1, 'source' => 'copy', 'project_ids' => [$this->eat->id], 'status' => InfaktDraftRequest::STATUS_SUBMITTED, 'attempt' => 1, 'task_reference' => 'ref-9']);

        $this->actingAs($this->owner)->get("/clients/{$this->client->id}/billing")
            ->assertInertia(fn (Assert $page) => $page->where('billing.invoice_drafts.groups.0.state', 'drafted')->where('billing.invoice_drafts.groups.0.net', 250000));

        $this->assertSame([InfaktDraftRequest::STATUS_CREATED, 'draft-ref-9'], [InfaktDraftRequest::query()->where('period', '2026-11')->sole()->status, InfaktDraftRequest::query()->where('period', '2026-11')->sole()->invoice_uuid]);
        $this->assertCount(0, $this->infaktPosts());
    }

    public function test_an_already_drafted_group_missing_from_the_mirror_is_mirrored_on_the_next_run(): void
    {
        $this->chainOctober();
        $this->infaktInvoices['draft-ref-9'] = $this->issuedInvoice('draft-ref-9', '', 250000, 'Utrzymanie sklepdemo', ['status' => 'draft', 'number' => null]);
        InfaktDraftRequest::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'period' => '2026-11', 'invoice_group' => 1, 'source' => 'copy', 'project_ids' => [$this->eat->id], 'status' => InfaktDraftRequest::STATUS_CREATED, 'attempt' => 1, 'invoice_uuid' => 'draft-ref-9']);

        $this->verb(['Polska', '--period=2026-11', '--project=sklepdemo', '--like=last', '--json'])->assertHeader('X-Crm-Exit', '0')->assertSee('already_drafted');

        $this->assertSame(1, Invoice::query()->where('external_uuid', 'draft-ref-9')->count());
    }

    public function test_the_copy_path_refuses_a_client_without_a_fee_bearing_position_as_the_retainer_path_does(): void
    {
        $this->chainOctober();
        $this->client->retainers()->update(['monthly_fee' => 0]);

        $this->verb(['Polska', '--period=2026-11', '--like=last', '--json'])->assertHeader('X-Crm-Exit', '1')->assertSee('no active fee-bearing retainer position');
        $this->actingAs($this->owner)->get("/clients/{$this->client->id}/billing")
            ->assertInertia(fn (Assert $page) => $page->where('billing.invoice_drafts', null));
        $this->assertCount(0, $this->infaktPosts());
    }

    public function test_a_source_refused_on_the_last_run_stays_a_pick_with_its_reason_in_the_block(): void
    {
        Carbon::setTestNow('2026-12-02 10:00:00');
        $this->chainOctober(['site-1610' => ['parent_id' => 20000777]]);
        foreach ([['20000501', 'cuw-1510', '15/10/2026', 300000], ['20000502', 'site-1610', '16/10/2026', 150000]] as [$id, $uuid, $number, $net]) {
            Invoice::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'external_id' => $id, 'external_uuid' => $uuid, 'number' => $number, 'status' => 'sent', 'kind' => 'vat', 'net_price' => $net]);
        }

        $this->actingAs($this->owner)->get("/clients/{$this->client->id}/billing")
            ->assertInertia(fn (Assert $page) => $page->where('billing.invoice_drafts.groups.1.state', 'ready'));
        $this->from("/clients/{$this->client->id}/billing")->post("/clients/{$this->client->id}/invoice-drafts", ['period' => '2026-11', 'group' => 2, 'like' => 'last'])
            ->assertSessionHas('error');

        $this->get("/clients/{$this->client->id}/billing")->assertInertia(fn (Assert $page) => $page
            ->where('billing.invoice_drafts.groups.1.state', 'needs_source')
            ->where('billing.invoice_drafts.groups.1.reason', 'settles_advance')
            ->where('billing.invoice_drafts.groups.0.state', 'ready')
            ->where('billing.invoice_drafts.groups.0.reason', null));
        $this->assertCount(0, $this->infaktPosts());
    }

    public function test_the_mirror_keeps_kind_and_parent_so_a_mirrored_correction_or_settlement_is_a_pick(): void
    {
        Carbon::setTestNow('2026-12-02 10:00:00');
        $this->chainOctober();
        Invoice::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'external_id' => '20000501', 'external_uuid' => 'cuw-1510', 'number' => '15/10/2026', 'status' => 'sent', 'kind' => 'correction', 'net_price' => 300000]);
        Invoice::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'external_id' => '20000502', 'external_uuid' => 'site-1610', 'number' => '16/10/2026', 'status' => 'sent', 'kind' => 'vat', 'parent_external_id' => '20000777', 'net_price' => 150000]);

        $this->actingAs($this->owner)->get("/clients/{$this->client->id}/billing")->assertInertia(fn (Assert $page) => $page
            ->where('billing.invoice_drafts.groups.0.reason', 'not_vat')
            ->where('billing.invoice_drafts.groups.1.reason', 'settles_advance')
            ->where('billing.invoice_drafts.groups.1.state', 'needs_source'));
        $this->assertSame(['vat', null], [$this->service()->mirrorInvoice($this->fixture(), $this->client)->kind, $this->service()->mirrorInvoice($this->fixture(), $this->client)->parent_external_id]);
    }

    public function test_the_draft_command_reports_a_blocked_month_instead_of_throwing(): void
    {
        $this->chainOctober();
        InfaktDraftRequest::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'period' => '2026-11', 'invoice_group' => 0, 'source' => 'copy', 'status' => InfaktDraftRequest::STATUS_CREATED, 'attempt' => 1]);

        $this->artisan('infakt:draft-invoice', ['client' => (string) $this->client->id, '--period' => '2026-11', '--like' => 'last'])
            ->expectsOutputToContain('not sent: the month holds an older copy not tied to a project')
            ->assertFailed();
        $this->assertCount(0, $this->infaktPosts());
    }

    public function test_an_invoice_with_contracts_or_orders_copies_them_as_date_and_number_only(): void
    {
        $source = $this->fixture();
        $source['transaction_terms'] = ['contracts' => [['id' => 5, 'date' => '2026-01-02', 'number' => 'UM/3/2026', 'note' => 'x']], 'orders' => [['id' => 6, 'number' => 'ZAM/5/2026']]];

        $invoice = $this->service()->copyInvoicePayload($source, '10000002', Carbon::parse('2026-11-01'))['invoice'];

        $this->assertSame(['contracts' => [['date' => '2026-01-02', 'number' => 'UM/3/2026']], 'orders' => [['number' => 'ZAM/5/2026']]], $invoice['transaction_terms']);
        InfaktService::assertDraftOnly(['invoice' => $invoice]);
    }

    /**
     * October drafted per group and since issued in Infakt; sklepdemo's invoice carries a Dodatkowy podmiot.
     *
     * @param  array<string, array<string, mixed>>  $overrides
     */
    private function chainOctober(array $overrides = []): void
    {
        $this->fakeInfaktInvoices([
            [...$this->fixture(), 'uuid' => 'cuw-1510', 'number' => '15/10/2026', 'client_id' => 10000002, 'status' => 'sent', ...($overrides['cuw-1510'] ?? [])],
            $this->issuedInvoice('site-1610', '16/10/2026', 150000, 'Utrzymanie tutaj site', ['invoice_date' => '2026-10-31', 'sale_date' => '2026-10-31', 'payment_date' => '2026-11-14', ...($overrides['site-1610'] ?? [])]),
        ]);
        foreach ([[1, 'cuw-1510', $this->eat], [2, 'site-1610', $this->site]] as [$group, $uuid, $project]) {
            InfaktDraftRequest::create([
                'account_id' => $this->account->id, 'client_id' => $this->client->id, 'period' => '2026-10', 'invoice_group' => $group, 'source' => 'retainer',
                'project_ids' => [$project->id], 'status' => InfaktDraftRequest::STATUS_CREATED, 'attempt' => 1, 'invoice_uuid' => $uuid,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/infakt/invoice-vat-third-party.json')), true);
    }

    private function service(): InfaktService
    {
        return app(InfaktDrafting::class)->service($this->client);
    }

    private function token(): string
    {
        $issued = $this->owner->createToken('agent', [AgentAbilities::READ, AgentAbilities::INVOICE_DRAFTS], now()->addDays(30));
        $issued->accessToken->forceFill(['account_id' => $this->account->id])->save();

        return $issued->plainTextToken;
    }

    /** @param  list<string>  $args */
    private function verb(array $args): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])->post('/agent/verb', ['verb' => 'invoice-draft', 'args' => $args]);
    }
}
