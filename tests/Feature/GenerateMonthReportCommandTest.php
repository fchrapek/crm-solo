<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * reports:generate bills the month just ended unless told otherwise, and
 * refuses a period it cannot read instead of writing one for 1969.
 */
final class GenerateMonthReportCommandTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00', 'UTC'));
        $account = Account::create(['name' => 'Acc']);
        $this->client = Client::create(['account_id' => $account->id, 'name' => 'ACME', 'type' => 'business']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_without_a_period_it_reports_the_previous_month(): void
    {
        $this->artisan('reports:generate', ['client' => (string) $this->client->id, '--composer' => 'structured_list'])
            ->expectsOutputToContain('[2026-09]')
            ->assertSuccessful();

        $report = ClientReport::sole();
        $this->assertSame('2026-09-01', $report->period_start->toDateString());
        $this->assertSame('2026-09-30', $report->period_end->toDateString());
    }

    public function test_a_given_period_is_used(): void
    {
        $this->artisan('reports:generate', ['client' => (string) $this->client->id, '--period' => '2026-07', '--composer' => 'structured_list'])
            ->assertSuccessful();

        $this->assertSame('2026-07-01', ClientReport::sole()->period_start->toDateString());
    }

    public function test_an_unreadable_period_is_refused_and_writes_nothing(): void
    {
        $this->artisan('reports:generate', ['client' => (string) $this->client->id, '--period' => '2026-13', '--composer' => 'structured_list'])
            ->expectsOutputToContain('expected YYYY-MM')
            ->assertFailed();

        $this->assertSame(0, ClientReport::count());
    }

    public function test_the_generate_command_refuses_a_month_that_has_a_report(): void
    {
        $existing = ClientReport::create([
            'account_id' => $this->client->account_id,
            'client_id' => $this->client->id,
            'period_type' => ClientReport::PERIOD_MONTH,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'actual_hours' => 0,
            'composer_key' => 'structured_list',
            'status' => ClientReport::STATUS_DRAFT,
        ]);

        $this->artisan('reports:generate', ['client' => (string) $this->client->id, '--period' => '2026-09', '--composer' => 'structured_list'])
            ->expectsOutputToContain("Report #{$existing->id} for [2026-09] already exists")
            ->assertFailed();

        $this->assertSame(1, ClientReport::count());

        $this->artisan('reports:generate', ['client' => (string) $this->client->id, '--period' => '2026-08', '--composer' => 'structured_list'])
            ->assertSuccessful();
        $this->assertSame(2, ClientReport::count());
    }
}
