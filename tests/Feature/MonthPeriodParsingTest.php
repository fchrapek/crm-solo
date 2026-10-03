<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\Integration;
use App\Models\MonthCloseRun;
use App\Models\Project;
use App\Models\User;
use App\Support\LocalCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A month given as YYYY-MM is that month whatever day the clock shows: on
 * the 29th, 30th or 31st of January, "2026-02" is February, not March, and
 * on 31 October "2026-09" is September. Anything that is not exactly a
 * month is refused before anything is written or sent.
 */
final class MonthPeriodParsingTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->account = Account::factory()->create();
        $this->owner = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->client = Client::factory()->create([
            'account_id' => $this->account->id, 'name' => 'Roofs Ltd', 'month_close_type' => 'maintenance',
            'include_in_month_close' => true, 'external_ids' => ['infakt' => '10000002'],
        ]);
        Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'General']);
        $this->client->retainers()->create([
            'account_id' => $this->account->id, 'monthly_hours' => 10, 'monthly_fee' => 220, 'currency' => 'PLN',
            'effective_from' => '2025-01-01', 'effective_to' => null,
        ]);
        Integration::create(['account_id' => $this->account->id, 'provider' => 'infakt', 'api_key' => 'k', 'is_enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function days(): array
    {
        return [
            'January 29, February' => ['2026-01-29 12:00:00', '2026-02', '2026-02-28'],
            'January 30, February' => ['2026-01-30 12:00:00', '2026-02', '2026-02-28'],
            'January 31, February' => ['2026-01-31 12:00:00', '2026-02', '2026-02-28'],
            'October 31, September' => ['2026-10-31 12:00:00', '2026-09', '2026-09-30'],
            'March 31, February of a leap year' => ['2028-03-31 12:00:00', '2028-02', '2028-02-29'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notMonths(): array
    {
        return [
            'month 13' => ['2026-13'],
            'month 00' => ['2026-00'],
            'one digit month' => ['2026-2'],
            'short year' => ['26-02'],
            'a full date' => ['2026-02-01'],
            'padded' => [' 2026-02'],
            'words' => ['February'],
        ];
    }

    #[DataProvider('days')]
    public function test_the_parser_never_takes_the_day_from_the_clock(string $now, string $period, string $lastDay): void
    {
        Carbon::setTestNow($now);

        $month = LocalCalendar::monthFrom($period);

        $this->assertSame($period.'-01 00:00:00', $month->format('Y-m-d H:i:s'));
        $this->assertSame($lastDay, $month->endOfMonth()->toDateString());
    }

    #[DataProvider('days')]
    public function test_draft_invoice_bills_the_month_named(string $now, string $period, string $lastDay): void
    {
        Carbon::setTestNow($now);

        $this->assertSame(0, Artisan::call('infakt:draft-invoice', ['client' => (string) $this->client->id, '--period' => $period, '--dry-run' => true]));

        $output = Artisan::output();
        $this->assertStringContainsString("({$period})", $output);
        $this->assertStringContainsString("\"sale_date\": \"{$lastDay}\"", $output);
    }

    #[DataProvider('days')]
    public function test_reports_generate_covers_the_month_named(string $now, string $period, string $lastDay): void
    {
        Carbon::setTestNow($now);

        $this->assertSame(0, Artisan::call('reports:generate', ['client' => (string) $this->client->id, '--period' => $period, '--composer' => 'structured_list']));

        $report = ClientReport::sole();
        $this->assertSame($period.'-01', $report->period_start->toDateString());
        $this->assertSame($lastDay, $report->period_end->toDateString());
    }

    #[DataProvider('days')]
    public function test_month_close_tick_and_the_picker_open_the_month_named(string $now, string $period, string $lastDay): void
    {
        Carbon::setTestNow($now);

        $this->assertSame(0, Artisan::call('month-close:tick', ['client' => (string) $this->client->id, 'step' => 'report', 'state' => 'done', '--period' => $period]));
        $this->actingAs($this->owner)->post(route('month-close.store'), ['client_id' => $this->client->id, 'period' => $period])->assertSessionHasNoErrors();

        $this->assertSame([$period], MonthCloseRun::pluck('period')->all());
        $this->assertSame($lastDay, LocalCalendar::monthFrom($period)->endOfMonth()->toDateString());
    }

    #[DataProvider('notMonths')]
    public function test_anything_but_a_month_is_refused_before_anything_happens(string $period): void
    {
        Carbon::setTestNow('2026-01-31 12:00:00');

        try {
            LocalCalendar::monthFrom($period);
            $this->fail("{$period} was accepted");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('YYYY-MM', $e->getMessage());
        }

        $this->assertSame(1, Artisan::call('infakt:draft-invoice', ['client' => (string) $this->client->id, '--period' => $period]));
        $this->assertSame(1, Artisan::call('reports:generate', ['client' => (string) $this->client->id, '--period' => $period, '--composer' => 'structured_list']));
        $this->assertSame(1, Artisan::call('month-close:tick', ['client' => (string) $this->client->id, 'step' => 'report', 'state' => 'done', '--period' => $period]));
        // The web stack trims input first, so a padded month is a month there.
        if (! LocalCalendar::isMonth(mb_trim($period))) {
            $this->actingAs($this->owner)->post(route('month-close.store'), ['client_id' => $this->client->id, 'period' => $period])->assertSessionHasErrors('period');
        }

        Http::assertNothingSent();
        $this->assertSame(0, ClientReport::count());
        $this->assertSame(0, MonthCloseRun::count());
    }

    public function test_the_month_close_page_falls_back_to_the_previous_month_for_a_bad_period(): void
    {
        Carbon::setTestNow('2026-10-31 12:00:00');
        config(['inertia.ssr.enabled' => false]);

        $this->actingAs($this->owner)->get(route('month-close.index', ['period' => '2026-13']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('period', '2026-09'));
    }
}
