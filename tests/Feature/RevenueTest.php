<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Revenue\RevenueAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class RevenueTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $acme;

    private Client $globex;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed USD→PLN rate so the aggregator never hits NBP during tests.
        Cache::put('fx_rate_pln_USD', 4.0, now()->addHours(24));

        $this->user = User::factory()->create([
            'account_id' => Account::create(['name' => 'Studio'])->id,
            'owner' => true,
        ]);

        $this->acme = $this->user->account->clients()->create(['name' => 'Acme', 'currency' => 'PLN']);
        $this->globex = $this->user->account->clients()->create(['name' => 'Globex', 'currency' => 'PLN']);

        // Amounts in grosze. Acme = 10 000 net PLN this year.
        $this->invoice($this->acme, 700000, 861000, '2026-02-10', '2026-02-20');
        $this->invoice($this->acme, 300000, 369000, '2026-05-10', null); // unpaid (no paid_date)
        $this->invoice($this->globex, 250000, 307500, '2026-03-01', '2026-03-15'); // 2 500 PLN
        // Prior-year invoice — outside YTD, used to exercise the window boundary.
        $this->invoice($this->acme, 999900, 1229877, '2025-06-01', '2025-06-10');
        // USD invoice: 5 000 USD → 20 000 PLN at the seeded 4.0 rate.
        $this->invoice($this->globex, 500000, 615000, '2026-04-01', '2026-04-05', 'USD');
    }

    public function test_accrual_totals_convert_usd_into_pln(): void
    {
        $data = $this->build(RevenueAggregator::BASIS_ACCRUAL);

        // Acme 10 000 + Globex (2 500 PLN + 5 000 USD × 4 = 20 000) = 32 500 net.
        $this->assertSame(32500.0, $data['summary']['total_net']);
        $this->assertSame(4, $data['summary']['invoice_count']);
        $this->assertSame(2, $data['summary']['client_count']);

        // Globex is now the top client thanks to the converted USD invoice.
        $top = $data['clients'][0];
        $this->assertSame('Globex', $top['name']);
        $this->assertSame(22500.0, $top['net']);
        $this->assertSame(69.2, $top['share_net']); // 22 500 / 32 500

        $this->assertSame(['USD' => 4.0], $data['fx']['rates']);
    }

    public function test_cash_basis_excludes_unpaid(): void
    {
        $data = $this->build(RevenueAggregator::BASIS_CASH);

        // Acme's 3 000 invoice is unpaid → excluded. Cash net = 7 000 + 22 500.
        $this->assertSame(29500.0, $data['summary']['total_net']);
        $this->assertSame(3, $data['summary']['invoice_count']);
    }

    public function test_currencies_are_listed(): void
    {
        $this->assertSame(['PLN', 'USD'], app(RevenueAggregator::class)->availableCurrencies($this->user->account_id));
    }

    public function test_client_summary_share_and_alltime(): void
    {
        $summary = app(RevenueAggregator::class)->clientSummary(
            $this->user->account_id,
            $this->acme->id,
            Carbon::create(2026, 1, 1)->startOfDay(),
            Carbon::create(2026, 12, 31)->endOfDay(),
        );

        $this->assertSame(10000.0, $summary['period_net']);
        $this->assertSame(30.8, $summary['share_net']); // 10 000 / 32 500
        $this->assertSame(19999.0, $summary['alltime_net']); // includes the 2025 invoice

        // No window = all-time period; share is measured against the whole book.
        $all = app(RevenueAggregator::class)->clientSummary($this->user->account_id, $this->acme->id);
        $this->assertSame(19999.0, $all['period_net']);
    }

    public function test_advance_invoices_do_not_double_count(): void
    {
        // One 5 000 PLN contract billed the Infakt way: the advance (ZAL) and
        // the settlement (RZL) BOTH carry the full contract net. Only the
        // settlement may count, on either basis.
        $this->invoice($this->acme, 500000, 615000, '2026-05-05', '2026-05-06', 'PLN', null, '1/ZAL/05/2026');
        $this->invoice($this->acme, 500000, 615000, '2026-06-15', '2026-06-16', 'PLN', null, '1/RZL/06/2026');

        $accrual = $this->build(RevenueAggregator::BASIS_ACCRUAL);
        $this->assertSame(37500.0, $accrual['summary']['total_net']); // 32 500 + 5 000, not + 10 000
        $this->assertSame(5, $accrual['summary']['invoice_count']); // ZAL not counted

        $cash = $this->build(RevenueAggregator::BASIS_CASH);
        $this->assertSame(34500.0, $cash['summary']['total_net']); // 29 500 + 5 000
    }

    public function test_accrual_recognises_by_sale_date(): void
    {
        // Issued in January 2027 for December 2026 work → belongs to 2026,
        // exactly as the accountant books it.
        $this->invoice($this->acme, 100000, 123000, '2027-01-05', null, 'PLN', '2026-12-28');
        // Issued in January 2026 for December 2025 work → NOT 2026 revenue.
        $this->invoice($this->acme, 99900, 122877, '2026-01-02', null, 'PLN', '2025-12-31');

        $data = $this->build(RevenueAggregator::BASIS_ACCRUAL);
        $this->assertSame(33500.0, $data['summary']['total_net']); // 32 500 + 1 000, without the 999
    }

    public function test_client_summary_excludes_advances(): void
    {
        $this->invoice($this->acme, 500000, 615000, '2026-07-01', null, 'PLN', null, '2/ZAL/07/2026');

        $summary = app(RevenueAggregator::class)->clientSummary(
            $this->user->account_id,
            $this->acme->id,
            Carbon::create(2026, 1, 1)->startOfDay(),
            Carbon::create(2026, 12, 31)->endOfDay(),
        );

        $this->assertSame(10000.0, $summary['period_net']); // the ZAL adds nothing
        $this->assertStringStartsWith('2026-07-01', (string) $summary['last_invoice_date']); // but it still counts as billing activity
    }

    public function test_revenue_page_renders(): void
    {
        $this->actingAs($this->user)
            ->get('/revenue')
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('revenue/index')
                ->where('filters.basis', 'accrual')
                // Loose cast: JSON serializes 32500.0 as int 32500; we only care about value.
                ->where('summary.total_net', fn ($v) => (float) $v === 32500.0)
                ->has('clients', 2)
                ->has('trend')
                ->where('fx.rates.USD', fn ($v) => (float) $v === 4.0)
            );
    }

    private function invoice(
        Client $client,
        int $net,
        int $gross,
        string $invoiceDate,
        ?string $paidDate,
        string $currency = 'PLN',
        ?string $saleDate = null,
        ?string $number = null,
    ): void {
        Invoice::create([
            'account_id' => $client->account_id,
            'client_id' => $client->id,
            'external_id' => 'inv-'.uniqid(),
            'number' => $number,
            'currency' => $currency,
            'net_price' => $net,
            'gross_price' => $gross,
            'tax_price' => $gross - $net,
            'paid_price' => $paidDate ? $gross : 0,
            'left_to_pay' => $paidDate ? 0 : $gross,
            'invoice_date' => $invoiceDate,
            'sale_date' => $saleDate ?? $invoiceDate,
            'paid_date' => $paidDate,
        ]);
    }

    private function build(string $basis): array
    {
        return app(RevenueAggregator::class)->build(
            $this->user->account_id,
            Carbon::create(2026, 1, 1)->startOfDay(),
            Carbon::create(2026, 12, 31)->endOfDay(),
            $basis,
        );
    }
}
