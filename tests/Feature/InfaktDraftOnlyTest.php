<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\InfaktDraftRequest;
use App\Models\Integration;
use App\Models\User;
use App\Services\Agent\AgentAbilities;
use App\Services\Integrations\InfaktService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Feature\Concerns\FakesInfaktInvoices;
use Tests\TestCase;

/**
 * Nothing in the CRM issues, sends or marks an invoice paid: every Infakt
 * write is one POST of a draft built from fixed lists of fields, and a draft
 * copied from a real invoice (Wystaw podobna) never carries its status,
 * number or payments.
 */
final class InfaktDraftOnlyTest extends TestCase
{
    use FakesInfaktInvoices;
    use RefreshDatabase;

    private Account $account;

    private Client $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 10:00:00');
        Sleep::fake();
        $this->account = Account::create(['name' => 'Studio']);
        $this->owner = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        Integration::create(['account_id' => $this->account->id, 'provider' => 'infakt', 'api_key' => 'test-key', 'is_enabled' => true]);
        $this->client = Client::factory()->create([
            'account_id' => $this->account->id, 'name' => 'Roofs Ltd', 'month_close_type' => 'maintenance',
            'include_in_month_close' => true, 'external_ids' => ['infakt' => '10000002'],
        ]);
        $this->client->retainers()->create(['account_id' => $this->account->id, 'monthly_hours' => 10, 'monthly_fee' => 2999, 'currency' => 'PLN', 'effective_from' => '2025-01-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_payload_with_a_field_off_the_list_is_refused_before_anything_is_sent(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $service = new InfaktService(Integration::query()->sole());

        foreach ([['status' => 'issued'], ['number' => '1/2026'], ['send_email' => true]] as $extra) {
            try {
                $service->postDraftInvoice(['invoice' => ['client_id' => 1, 'services' => [], ...$extra]]);
                $this->fail('A payload with '.array_key_first($extra).' was sent.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(array_key_first($extra), $e->getMessage());
            }
        }
        try {
            $service->postDraftInvoice(['invoice' => ['client_id' => 1, 'services' => [['name' => 'x', 'paid' => true]]]]);
            $this->fail('A line with paid was sent.');
        } catch (RuntimeException) {
        }

        Http::assertNothingSent();
    }

    public function test_a_payload_with_a_field_on_no_list_is_refused_at_the_post_even_when_nothing_forbids_it(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $service = new InfaktService(Integration::query()->sole());

        foreach ([['invoice' => ['client_id' => 1, 'services' => [], 'colour' => 'red']], ['invoice' => ['client_id' => 1, 'services' => [], 'third_party_addresses' => [['company_name' => 'A', 'role_code' => '2', 'id' => 4]]]]] as $payload) {
            try {
                $service->postDraftInvoice($payload);
                $this->fail('An off-list payload was sent.');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression('/colour|\bid\b/', $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_a_field_hidden_under_a_scalar_or_a_contract_number_is_refused_by_path_and_type(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $service = new InfaktService(Integration::query()->sole());
        $line = ['name' => 'x', 'unit_net_price' => 100, 'quantity' => 1, 'tax_symbol' => '23'];

        foreach ([
            'invoice.notes' => ['notes' => ['colour' => 'red']],
            'invoice.transaction_terms.contracts[0].number' => ['transaction_terms' => ['contracts' => [['date' => '2026-01-02', 'number' => ['send_to_ksef' => true]]]]],
            'invoice.services[0].quantity' => ['services' => [[...$line, 'quantity' => 'one']]],
            'invoice.services' => ['services' => ['first' => $line]],
            'invoice.split_payment' => ['split_payment' => 'yes'],
        ] as $path => $fields) {
            try {
                $service->postDraftInvoice(['invoice' => ['client_id' => 1, 'services' => [$line], ...$fields]]);
                $this->fail("A payload with a bad {$path} was sent.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($path, $e->getMessage());
            }
        }

        InfaktService::assertDraftOnly(['invoice' => ['client_id' => 1, 'services' => [$line], 'transaction_terms' => ['contracts' => [['date' => '2026-01-02', 'number' => 'UM/3/2026']]]]]);
        Http::assertNothingSent();
    }

    public function test_like_last_copies_the_latest_real_vat_invoice_with_new_dates_and_sends_nothing_on_a_dry_run(): void
    {
        $this->fakeInfakt();

        $response = $this->verb('invoice-draft', ['Roofs', '--period=2026-10', '--like=last', '--dry-run', '--json'])->assertHeader('X-Crm-Exit', '0');

        $this->assertEquals([
            'currency' => 'PLN', 'notes' => 'internal', 'payment_method' => 'transfer',
            'services' => [['name' => 'Opieka nad stroną', 'unit_net_price' => 109900, 'quantity' => 1, 'tax_symbol' => '23', 'unit' => 'usł.']],
            'client_id' => 10000002, 'sale_date' => '2026-10-31', 'invoice_date' => '2026-11-03', 'payment_date' => '2026-11-17',
        ], $response->json('results.0.payload.invoice'));
        $this->assertSame(['kind' => 'copy', 'invoice_uuid' => 'uuid-41', 'invoice_number' => '15/09/2026'], $response->json('results.0.source'));
        $this->assertSame('preview', $response->json('results.0.outcome'));
        $this->assertCount(0, $this->infaktPosts());
    }

    public function test_a_like_draft_is_sent_once_as_a_draft_and_a_second_run_skips_it(): void
    {
        $this->fakeInfakt();

        $this->verb('invoice-draft', ['Roofs', '--period=2026-10', '--like=last', '--json'])->assertHeader('X-Crm-Exit', '0')->assertSee('"outcome":"created"', false);
        $this->verb('invoice-draft', ['Roofs', '--period=2026-10', '--like=last', '--json'])->assertHeader('X-Crm-Exit', '0')->assertSee('already_drafted');

        $posts = $this->infaktPosts();
        $this->assertCount(1, $posts);
        $this->assertSame('https://api.infakt.pl/v3/async/invoices.json', $posts->first()->url());
        $this->assertArrayNotHasKey('status', $posts->first()->data()['invoice']);
        $row = InfaktDraftRequest::query()->sole();
        $this->assertSame([InfaktDraftRequest::STATUS_CREATED, 1, 'copy', 'uuid-41', '15/09/2026'], [$row->status, $row->invoice_group, $row->source, $row->source_invoice_uuid, $row->source_invoice_number]);
    }

    public function test_the_retainer_draft_is_the_default_and_a_token_without_the_group_cannot_send_it(): void
    {
        $this->fakeInfakt();

        $this->verb('invoice-draft', ['Roofs', '--period=2026-10', '--dry-run', '--json'])->assertHeader('X-Crm-Exit', '0')->assertSee('"unit_net_price":299900', false);

        $plain = $this->token([AgentAbilities::READ, AgentAbilities::REPORTS]);
        $this->withHeaders(['Authorization' => 'Bearer '.$plain])->post('/agent/verb', ['verb' => 'invoice-draft', 'args' => ['Roofs', '--period=2026-10']])->assertForbidden();
        $this->assertCount(0, $this->infaktPosts());
    }

    public function test_like_says_needs_source_when_the_client_has_no_real_invoice_yet(): void
    {
        $this->fakeInfaktInvoices([['id' => 20000009, 'uuid' => 'uuid-9', 'number' => null, 'kind' => 'vat', 'status' => 'draft', 'client_id' => 10000002, 'invoice_date' => '2026-10-01', 'services' => []]]);

        $this->verb('invoice-draft', ['Roofs', '--period=2026-10', '--like=last', '--dry-run', '--json'])->assertHeader('X-Crm-Exit', '1')
            ->assertSee('"outcome":"needs_source"', false)->assertSee('no issued VAT invoice');
    }

    public function test_a_group_drafted_from_the_retainer_is_not_drafted_again_as_a_copy_and_next_month_copies_it(): void
    {
        $this->fakeInfakt();

        $this->verb('invoice-draft', ['Roofs', '--period=2026-10', '--json'])->assertHeader('X-Crm-Exit', '0');
        $this->verb('invoice-draft', ['Roofs', '--period=2026-10', '--like=last', '--json'])->assertHeader('X-Crm-Exit', '0')->assertSee('already_drafted');

        $this->infaktInvoices['draft-ref-3'] = [...$this->infaktInvoices['draft-ref-3'], 'status' => 'sent', 'number' => '1/10/2026'];
        $this->verb('invoice-draft', ['Roofs', '--period=2026-11', '--like=last', '--json'])->assertHeader('X-Crm-Exit', '0')->assertSee('"invoice_number":"1/10/2026"', false);
        $this->verb('invoice-draft', ['Roofs', '--period=2026-11', '--json'])->assertHeader('X-Crm-Exit', '0')->assertSee('already_drafted');

        $this->assertCount(2, $this->infaktPosts());
    }

    public function test_a_retainer_draft_infakt_rejected_does_not_block_the_copy(): void
    {
        $this->fakeInfakt();
        InfaktDraftRequest::create([
            'account_id' => $this->account->id, 'client_id' => $this->client->id, 'period' => '2026-10', 'invoice_group' => 1,
            'status' => InfaktDraftRequest::STATUS_FAILED, 'attempt' => 1,
        ]);

        $this->verb('invoice-draft', ['Roofs', '--period=2026-10', '--like=last', '--json'])->assertHeader('X-Crm-Exit', '0')->assertSee('"outcome":"created"', false);
        $this->assertSame([InfaktDraftRequest::SOURCE_COPY, 'uuid-41', 2], [InfaktDraftRequest::query()->sole()->source, InfaktDraftRequest::query()->sole()->source_invoice_uuid, InfaktDraftRequest::query()->sole()->attempt]);
    }

    private function fakeInfakt(): void
    {
        $this->fakeInfaktInvoices([
            [
                'id' => 20000041, 'uuid' => 'uuid-41', 'number' => '15/09/2026', 'kind' => 'vat', 'status' => 'paid', 'client_id' => 10000002, 'invoice_date' => '2026-09-30', 'sale_date' => '2026-09-30',
                'payment_date' => '2026-10-14', 'payment_method' => 'transfer', 'currency' => 'PLN', 'paid_date' => '2026-10-10', 'notes' => 'internal', 'net_price' => 109900,
                'services' => [['id' => 7, 'name' => 'Opieka nad stroną', 'unit_net_price' => 109900, 'net_price' => 109900, 'quantity' => 1.0, 'tax_symbol' => '23', 'unit' => 'usł.']],
            ],
            ['id' => 20000040, 'uuid' => 'uuid-40', 'number' => '9/08/2026', 'kind' => 'vat', 'status' => 'paid', 'client_id' => 10000002, 'invoice_date' => '2026-08-31', 'services' => [['name' => 'Old', 'unit_net_price' => 1, 'quantity' => 1, 'tax_symbol' => '23']]],
        ]);
    }

    /** @param  list<string>  $abilities */
    private function token(array $abilities): string
    {
        $issued = $this->owner->createToken('agent', $abilities, now()->addDays(30));
        $issued->accessToken->forceFill(['account_id' => $this->account->id])->save();

        return $issued->plainTextToken;
    }

    /** @param  list<string>  $args */
    private function verb(string $verb, array $args): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token([AgentAbilities::READ, AgentAbilities::INVOICE_DRAFTS])])
            ->post('/agent/verb', ['verb' => $verb, 'args' => $args]);
    }
}
