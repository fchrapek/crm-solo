<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Payment state comes from `status`: Infakt leaves the amount columns unsettled on paid invoices. */
final class ClientInvoicesTabTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_invoices_tab_carries_infakt_links_and_the_status(): void
    {
        config(['services.infakt.invoice_url' => 'https://app.infakt.test/app/faktury/{id}']);

        $account = Account::create(['name' => 'Acme']);
        $user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $client = Client::create(['account_id' => $account->id, 'name' => 'Test Client']);

        Invoice::create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'external_id' => '20000001',
            'number' => '18/07/2026',
            'status' => 'paid',
            'currency' => 'PLN',
            'net_price' => 98700,
            'gross_price' => 121401,
            'tax_price' => 22701,
            // Exactly the shape Infakt returns for a settled invoice: marked
            // paid, yet paid_price is 0 and the full gross is still "left".
            'paid_price' => 0,
            'left_to_pay' => 121401,
            // Relative to the clock: the Invoices tab defaults to the current
            // year, so a hardcoded year would break at rollover.
            'invoice_date' => now()->startOfYear()->addDays(14)->toDateString(),
        ]);

        $response = $this->actingAs($user)->get("/clients/{$client->id}/edit");

        $response->assertOk();
        $tab = $response->viewData('page')['props']['invoices'];

        $this->assertCount(1, $tab['rows']);
        $this->assertSame(1, $tab['total']);
        $this->assertFalse($tab['truncated']);
        $this->assertSame('18/07/2026', $tab['rows'][0]['number']);
        $this->assertSame('paid', $tab['rows'][0]['status']);
        $this->assertSame(1214.01, $tab['rows'][0]['gross'], 'Grosze are converted to major units for display.');
        $this->assertSame(987.0, $tab['rows'][0]['net']);
        $this->assertSame(
            'https://app.infakt.test/app/faktury/20000001',
            $tab['rows'][0]['external_url'],
            'The deep link interpolates the Infakt id, which the API never returns as a URL.',
        );
    }

    public function test_the_last_twelve_months_start_a_year_before_the_local_today(): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);
        // 00:30 on 1 November in Warsaw, still 31 October in UTC.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-31 23:30:00', 'UTC'));
        $account = Account::create(['name' => 'Acme']);
        $user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $client = Client::create(['account_id' => $account->id, 'name' => 'Test Client']);
        foreach (['2025-10-31' => 10000, '2025-11-01' => 20000] as $date => $net) {
            Invoice::create([
                'account_id' => $account->id,
                'client_id' => $client->id,
                'external_id' => 'x'.$date,
                'number' => $date,
                'status' => 'paid',
                'currency' => 'PLN',
                'net_price' => $net,
                'gross_price' => $net,
                'tax_price' => 0,
                'paid_price' => 0,
                'left_to_pay' => 0,
                'invoice_date' => $date,
            ]);
        }

        $props = $this->actingAs($user)->get("/clients/{$client->id}/edit?invoice_year=last12")->viewData('page')['props'];

        $this->assertSame(200.0, $props['revenue']['period_net']);
        $this->assertSame(1, $props['invoices']['total']);
        $this->assertSame('2025-11-01', $props['invoices']['rows'][0]['number']);
        $this->assertSame('last12', $props['invoiceFilters']['year']);
        $this->assertNull($props['invoiceFilters']['month']);
    }

    public function test_the_last_twelve_months_count_by_sale_date_leave_out_advances_and_ignore_a_month(): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-20 10:00:00', 'UTC'));
        $account = Account::create(['name' => 'Acme']);
        $user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $client = Client::create(['account_id' => $account->id, 'name' => 'Test Client']);
        $make = fn (string $number, int $net, string $invoiceDate, ?string $saleDate = null) => Invoice::create([
            'account_id' => $account->id, 'client_id' => $client->id, 'external_id' => $number, 'number' => $number,
            'status' => 'paid', 'currency' => 'PLN', 'net_price' => $net, 'gross_price' => $net,
            'invoice_date' => $invoiceDate, 'sale_date' => $saleDate,
        ]);
        $make('1/03/2026', 10000, '2026-03-02');
        $make('2/03/2026/ZAL/1', 50000, '2026-03-05');
        // Issued inside the year, but its sale date is older, so the books put it outside.
        $make('3/10/2025', 30000, '2025-10-25', '2025-10-10');

        $props = $this->actingAs($user)->get("/clients/{$client->id}/edit?invoice_year=last12&invoice_month=3")->viewData('page')['props'];

        $this->assertSame(100.0, $props['revenue']['period_net']);
        $this->assertSame(1, $props['revenue']['period_invoice_count']);
        $this->assertSame(2, $props['invoices']['total']);
        $this->assertNull($props['invoiceFilters']['month']);
    }

    public function test_the_tab_lists_every_invoice_of_the_period_newest_first(): void
    {
        $account = Account::create(['name' => 'Acme']);
        $user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $client = Client::create(['account_id' => $account->id, 'name' => 'Test Client']);

        foreach (range(1, 5) as $i) {
            Invoice::create([
                'account_id' => $account->id,
                'client_id' => $client->id,
                'external_id' => (string) $i,
                'number' => "{$i}/2026",
                'status' => 'paid',
                'currency' => 'PLN',
                'net_price' => 1000,
                'gross_price' => 1230,
                // Current-year months, since the tab's default filter is the
                // current year.
                'invoice_date' => now()->startOfYear()->addMonths($i - 1)->toDateString(),
            ]);
        }

        $props = $this->actingAs($user)->get("/clients/{$client->id}/edit")->viewData('page')['props'];

        $this->assertCount(5, $props['invoices']['rows']);
        $this->assertSame(5, $props['invoices']['total']);
        $this->assertFalse($props['invoices']['truncated']);
        $this->assertSame('5/2026', $props['invoices']['rows'][0]['number']);
    }

    public function test_invoices_tab_filters_by_year_and_month_defaulting_to_current_year(): void
    {
        $account = Account::create(['name' => 'Acme']);
        $user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $client = Client::create(['account_id' => $account->id, 'name' => 'Test Client']);

        $make = function (string $number, string $date) use ($account, $client): void {
            Invoice::create([
                'account_id' => $account->id,
                'client_id' => $client->id,
                'external_id' => $number,
                'number' => $number,
                'status' => 'paid',
                'currency' => 'PLN',
                'net_price' => 1000,
                'gross_price' => 1230,
                'invoice_date' => $date,
            ]);
        };

        $thisYear = now()->year;
        $lastYear = $thisYear - 1;
        $make('THIS/MAR', now()->startOfYear()->addMonths(2)->toDateString());
        $make('THIS/JUL', now()->startOfYear()->addMonths(6)->toDateString());
        $make('LAST/NOV', now()->subYear()->startOfYear()->addMonths(10)->toDateString());

        $url = "/clients/{$client->id}/edit";

        // Default = current year.
        $props = $this->actingAs($user)->get($url)->viewData('page')['props'];
        $this->assertSame(2, $props['invoices']['total']);
        $this->assertSame($thisYear, $props['invoiceFilters']['year']);

        // Explicit past year.
        $props = $this->actingAs($user)->get("{$url}?invoice_year={$lastYear}")->viewData('page')['props'];
        $this->assertSame(1, $props['invoices']['total']);
        $this->assertSame('LAST/NOV', $props['invoices']['rows'][0]['number']);

        // Year + month narrows further.
        $props = $this->actingAs($user)->get("{$url}?invoice_year={$thisYear}&invoice_month=7")->viewData('page')['props'];
        $this->assertSame(1, $props['invoices']['total']);
        $this->assertSame('THIS/JUL', $props['invoices']['rows'][0]['number']);

        // 'all' widens to everything; both years offered in the filter.
        $props = $this->actingAs($user)->get("{$url}?invoice_year=all")->viewData('page')['props'];
        $this->assertSame(3, $props['invoices']['total']);
        $this->assertSame([$thisYear, $lastYear], $props['invoiceFilters']['years']);
    }

    public function test_invoices_of_other_clients_do_not_leak(): void
    {
        $account = Account::create(['name' => 'Acme']);
        $user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $client = Client::create(['account_id' => $account->id, 'name' => 'Test Client']);
        $other = Client::create(['account_id' => $account->id, 'name' => 'Other Client']);

        Invoice::create([
            'account_id' => $account->id,
            'client_id' => $other->id,
            'external_id' => '1',
            'number' => 'OTHER/1',
            'status' => 'paid',
            'currency' => 'PLN',
            'net_price' => 1000,
            'gross_price' => 1230,
            'invoice_date' => now()->startOfYear()->addMonths(6)->toDateString(),
        ]);

        $props = $this->actingAs($user)->get("/clients/{$client->id}/edit?invoice_year=all")->viewData('page')['props'];

        $this->assertCount(0, $props['invoices']['rows']);
        $this->assertSame(0, $props['invoices']['total']);
        $this->assertNull($props['revenue']);
    }
}
