<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\ClientRetainer;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Agent\ClientBrief;
use App\Services\Clients\HoursBank;
use App\Services\Clients\HoursBankMonth;
use App\Services\Reports\ReportDataAggregator;
use App\Support\LocalCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HoursBankTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $user;

    private Client $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.display_timezone' => 'Europe/Warsaw']);
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'UTC'));

        $this->account = Account::create(['name' => 'Acme']);
        $this->user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->client = $this->account->clients()->create(['name' => 'Acme', 'currency' => 'PLN']);
        $this->project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'name' => 'Site',
        ]);
    }

    public function test_the_balance_carries_from_report_to_report(): void
    {
        $this->retainer(10, '2026-01-01');
        $this->report('2026-08', opening: 0, contracted: 10, actual: 7);
        $this->report('2026-09', opening: 3, contracted: 10, actual: 15);

        $overview = $this->overview();
        $this->assertSame(-2.0, $overview['carried_hours']);
        $this->assertSame(10.0, $overview['contracted_hours']);
        $this->assertSame(8.0, $overview['available_hours']);

        $this->assertSame(-2.0, $this->openingFor('2026-10'));
        $this->assertSame(3.0, $this->openingFor('2026-09'));
        $this->assertSame(0.0, $this->openingFor('2026-08'));
    }

    public function test_a_retainer_changed_mid_month_counts_the_one_in_force_on_the_first_as_the_report_does(): void
    {
        $this->retainer(10, '2026-01-01', to: '2026-10-15', cap: 15);
        $this->retainer(20, '2026-10-15', cap: 30);

        $overview = $this->overview();
        $this->assertSame(10.0, $overview['contracted_hours']);
        $this->assertSame(10.0, $overview['available_hours']);
        $this->assertSame(10.0, $this->brief()['contracted']);
        $this->assertSame(15.0, $this->brief()['bank']['cap']);

        $context = $this->contextFor('2026-10');
        $this->assertSame(10.0, $context->contractedHours());
        $this->assertSame(15.0, $context->rolloverCapHours);
    }

    public function test_a_retainer_ending_on_the_first_is_not_active_that_month(): void
    {
        $this->retainer(10, '2026-01-01', to: '2026-10-01');
        $this->retainer(12, '2026-10-01');

        $this->assertSame(12.0, $this->overview()['contracted_hours']);
        $this->assertSame(12.0, $this->contextFor('2026-10')->contractedHours());
        $this->assertSame(10.0, $this->contextFor('2026-09')->contractedHours());
    }

    public function test_a_report_without_contracted_hours_carries_its_opening_untouched(): void
    {
        $this->report('2026-09', opening: 4, contracted: null, actual: 3);

        $overview = $this->overview();
        $this->assertSame(0.0, $overview['contracted_hours']);
        $this->assertSame(4.0, $overview['carried_hours']);
        $this->assertSame(4.0, $overview['available_hours']);
        $this->assertSame(4.0, app(HoursBank::class)->month($this->client, '2026-09')->closingBalanceHours());

        $context = $this->contextFor('2026-10');
        $this->assertNull($context->contractedHours());
        $this->assertSame(4.0, $context->openingBalanceHours);
    }

    public function test_next_month_opens_from_the_rounded_report_hours_not_the_entries(): void
    {
        $this->retainer(10, '2026-01-01');
        $task = $this->task(reportable: true);
        $this->entry($task, '2026-09-10 08:00:00', 567);
        $this->report('2026-09', opening: 0, contracted: 10, actual: 10);

        $this->assertSame(0.0, $this->overview()['carried_hours']);
        $this->assertSame(0.0, $this->openingFor('2026-10'));
        $this->assertSame(9.45, $this->contextFor('2026-09')->actualHours);
    }

    public function test_a_surplus_stops_at_the_cap_and_an_overrun_carries_as_debt(): void
    {
        $this->retainer(10, '2026-01-01', cap: 15);
        $this->report('2026-08', opening: 8, contracted: 10, actual: 5, cap: 15);

        $this->assertSame(10.0, $this->openingFor('2026-09'));

        $this->report('2026-09', opening: 10, contracted: 10, actual: 19, cap: 15);

        $overview = $this->overview();
        $this->assertSame(-4.0, $overview['carried_hours']);
        $this->assertSame(6.0, $overview['available_hours']);
        $this->assertSame(-4.0, $this->openingFor('2026-10'));
    }

    public function test_screens_count_reportable_time_in_the_local_month_and_the_brief_keeps_all_of_it_labelled(): void
    {
        $this->retainer(10, '2026-01-01');
        $this->entry($this->task(reportable: true), '2026-10-02 08:00:00', 125);
        $this->entry($this->task(reportable: false), '2026-10-03 08:00:00', 180);
        $this->entry(null, '2026-10-04 08:00:00', 60);
        $this->entry($this->task(reportable: true), '2026-11-02 08:00:00', 30);
        $this->entry($this->task(reportable: true), '2026-09-30 21:00:00', 45);
        $this->entry($this->task(reportable: true), '2026-09-30 22:30:00', 15);

        $this->assertSame(2.33, $this->overview()['month_hours']);
        $this->assertSame(2.33, $this->contextFor('2026-10')->actualHours);

        $brief = $this->brief();
        $this->assertSame(6.33, $brief['month']);
        $this->assertSame('all_logged_time', $brief['month_counts']);
        $this->assertSame('reportable_time', $brief['bank']['counts']);
        $this->assertSame(2.33, $brief['bank']['used']);
        $this->assertSame(6.33, $brief['bank']['logged']);
        $this->assertSame(7.67, $brief['bank']['remaining']);
    }

    public function test_several_positions_count_the_leading_one_everywhere_as_the_report_does(): void
    {
        $this->retainer(10, '2026-01-01');
        $this->retainer(5, '2026-01-01');

        $overview = $this->overview();
        $this->assertSame(10.0, $overview['contracted_hours']);
        $this->assertSame(10.0, $overview['available_hours']);
        $this->assertSame(10.0, $this->brief()['contracted']);
        $this->assertSame(10.0, $this->contextFor('2026-10')->contractedHours());
        $this->assertCount(2, app(ClientBrief::class)->for($this->client->fresh())['retainers'], 'The brief still lists every position.');
    }

    public function test_a_draft_for_the_month_in_progress_keeps_its_balance_but_counts_live_hours(): void
    {
        $this->retainer(10, '2026-01-01');
        $this->report('2026-09', opening: 0, contracted: 10, actual: 8);
        $this->report('2026-10', opening: 5, contracted: 10, actual: 1.5);
        $this->entry($this->task(reportable: true), '2026-10-02 08:00:00', 360);

        $overview = $this->overview();
        $this->assertSame(5.0, $overview['carried_hours']);
        $this->assertSame(15.0, $overview['available_hours']);
        $this->assertSame(6.0, $overview['month_hours']);
        $this->assertSame('draft', $this->brief()['bank']['report_status']);
        $this->assertSame(2.0, $this->openingFor('2026-10'), 'Generating the month again still carries in from the report before it.');
    }

    public function test_a_finalized_report_for_the_month_in_progress_governs_its_hours(): void
    {
        $this->retainer(10, '2026-01-01');
        $this->report('2026-10', opening: 5, contracted: 10, actual: 1.5, status: ClientReport::STATUS_FINALIZED);
        $this->entry($this->task(reportable: true), '2026-10-02 08:00:00', 360);

        $this->assertSame(1.5, $this->overview()['month_hours']);
        $this->assertSame('finalized', $this->brief()['bank']['report_status']);
        $this->assertSame(6.0, $this->brief()['bank']['logged']);
    }

    public function test_a_draft_for_a_month_that_has_ended_governs_its_hours(): void
    {
        $this->retainer(10, '2026-01-01');
        $this->entry($this->task(reportable: true), '2026-09-10 08:00:00', 567);
        $this->report('2026-09', opening: 2, contracted: 10, actual: 10);

        $september = app(HoursBank::class)->month($this->client, '2026-09');
        $this->assertSame(10.0, $september->usedHours);
        $this->assertSame(2.0, $september->closingBalanceHours());
        $this->assertSame('draft', $september->reportStatus);
        $this->assertNull(app(HoursBank::class)->month($this->client, '2026-10')->reportStatus);
    }

    public function test_a_report_that_does_not_cover_the_whole_month_is_ignored_for_its_figures(): void
    {
        $this->retainer(10, '2026-01-01');
        $this->report('2026-09', opening: 0, contracted: 10, actual: 8);
        $this->entry($this->task(reportable: true), '2026-10-02 08:00:00', 60);

        $this->report('2026-10', opening: 50, contracted: 99, actual: 30, status: ClientReport::STATUS_SENT, end: '2026-10-07');
        $this->report('2026-10', opening: 60, contracted: 98, actual: 40, status: ClientReport::STATUS_SENT, end: '2026-11-30');

        $overview = $this->overview();
        $this->assertSame(2.0, $overview['carried_hours']);
        $this->assertSame(10.0, $overview['contracted_hours']);
        $this->assertSame(1.0, $overview['month_hours']);
        $this->assertNull($this->brief()['bank']['report_status']);
    }

    public function test_a_sent_report_outranks_a_newer_draft_for_the_same_period(): void
    {
        $this->retainer(10, '2026-01-01');
        $this->report('2026-09', opening: 0, contracted: 10, actual: 3, status: ClientReport::STATUS_SENT);
        $this->report('2026-09', opening: 0, contracted: 10, actual: 7);

        $this->assertSame(7.0, $this->overview()['carried_hours']);
        $this->assertSame(7.0, $this->openingFor('2026-10'));
        $this->assertSame(3.0, app(HoursBank::class)->month($this->client, '2026-09')->usedHours);
    }

    public function test_a_sent_report_outranks_a_newer_finalized_one(): void
    {
        $this->report('2026-09', opening: 0, contracted: 10, actual: 3, status: ClientReport::STATUS_SENT);
        $this->report('2026-09', opening: 0, contracted: 10, actual: 5, status: ClientReport::STATUS_FINALIZED);

        $this->assertSame(7.0, $this->openingFor('2026-10'));
        $this->assertSame(3.0, app(HoursBank::class)->month($this->client, '2026-09')->usedHours);
    }

    public function test_a_finalized_report_outranks_a_newer_draft(): void
    {
        $this->report('2026-09', opening: 0, contracted: 10, actual: 4, status: ClientReport::STATUS_FINALIZED);
        $this->report('2026-09', opening: 0, contracted: 10, actual: 9);

        $this->assertSame(6.0, $this->openingFor('2026-10'));
        $this->assertSame(4.0, app(HoursBank::class)->month($this->client, '2026-09')->usedHours);
    }

    public function test_of_same_status_reports_ending_together_the_whole_month_carries_not_a_newer_partial_one(): void
    {
        $whole = $this->report('2026-09', opening: 0, contracted: 10, actual: 3, status: ClientReport::STATUS_SENT);
        $partial = $this->report('2026-09', opening: 0, contracted: 10, actual: 8, status: ClientReport::STATUS_SENT);
        $partial->update(['period_start' => '2026-09-16']);

        $this->assertGreaterThan($whole->id, $partial->id);
        $this->assertSame(7.0, $this->openingFor('2026-10'));
        $this->assertSame($whole->id, app(HoursBank::class)->month($this->client, '2026-09')->reportId);
    }

    public function test_the_carry_in_hydrates_only_the_latest_period_and_never_older_ones(): void
    {
        $second = $this->account->clients()->create(['name' => 'Second', 'currency' => 'PLN']);
        foreach (range(1, 9) as $month) {
            $this->report(sprintf('2026-%02d', $month), opening: 0, contracted: 10, actual: $month);
            $this->report(sprintf('2026-%02d', $month), opening: 0, contracted: 10, actual: 1, client: $second);
        }
        $this->report('2026-09', opening: 0, contracted: 10, actual: 2, status: ClientReport::STATUS_SENT, client: $second);

        $hydrated = 0;
        ClientReport::retrieved(function () use (&$hydrated): void {
            $hydrated++;
        });

        $this->assertSame(1.0, app(HoursBank::class)->openingBalance($this->client, Carbon::parse('2026-10-01')));
        $this->assertSame(1, $hydrated);

        $hydrated = 0;
        $months = app(HoursBank::class)->months([$this->client->id => '2026-10', $second->id => '2026-10']);
        $this->assertSame(3, $hydrated, 'Only the tied latest rows load: one for the first client, two for the second.');
        $this->assertSame(1.0, $months[$this->client->id]->openingBalanceHours);
        $this->assertSame(8.0, $months[$second->id]->openingBalanceHours);
    }

    public function test_a_client_without_a_leading_position_says_it_has_no_retainer(): void
    {
        $this->report('2026-09', opening: 4, contracted: 10, actual: 0);
        ClientRetainer::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'monthly_hours' => 20,
            'effective_from' => '2026-01-01',
            'is_active' => false,
        ]);

        $bank = $this->brief()['bank'];
        $this->assertFalse($bank['has_retainer']);
        $this->assertNull($bank['contracted']);
        $this->assertSame(14.0, $bank['available']);
        $this->assertNull($this->client->activeRetainerOn(Carbon::parse('2026-10-01')));

        $this->retainer(10, '2026-01-01');
        $this->assertTrue($this->brief()['bank']['has_retainer']);
    }

    public function test_of_two_reports_for_one_period_the_newer_one_carries(): void
    {
        $this->retainer(10, '2026-01-01');
        $this->report('2026-09', opening: 0, contracted: 10, actual: 8);
        $this->report('2026-09', opening: 0, contracted: 10, actual: 3);

        $this->assertSame(7.0, $this->overview()['carried_hours']);
        $this->assertSame(7.0, $this->openingFor('2026-10'));
    }

    public function test_many_clients_cost_the_same_queries_as_one_and_match_the_single_figures(): void
    {
        $this->retainer(10, '2026-01-01');
        $this->report('2026-09', opening: 0, contracted: 10, actual: 8);
        $this->entry($this->task(reportable: true), '2026-10-02 08:00:00', 90);

        $others = collect(range(1, 4))->map(function (int $i): Client {
            $client = $this->account->clients()->create(['name' => "Other {$i}", 'currency' => 'PLN']);
            ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'monthly_hours' => $i, 'effective_from' => '2026-01-01']);

            return $client;
        });

        $bank = app(HoursBank::class);
        $queries = function (callable $run): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $run();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $single = $queries(fn () => $bank->months([$this->client->id => '2026-10']));
        $many = $queries(fn () => $bank->months(collect([$this->client])->merge($others)->mapWithKeys(fn (Client $c): array => [$c->id => '2026-10'])->all()));
        $this->assertSame($single, $many);

        $months = $bank->months(collect($others)->mapWithKeys(fn (Client $c): array => [$c->id => '2026-10'])->all());
        $this->assertEquals($bank->month($this->client, '2026-10'), $bank->months(collect([$this->client, ...$others])->mapWithKeys(fn (Client $c): array => [$c->id => '2026-10'])->all())[$this->client->id]);
        $this->assertSame([1.0, 2.0, 3.0, 4.0], array_values(array_map(fn (HoursBankMonth $m): ?float => $m->contractedHours, $months)));
        $this->assertSame(1.5, $bank->month($this->client, '2026-10')->usedHours);
        $this->assertSame(10.5, $bank->month($this->client, '2026-10')->closingBalanceHours());
    }

    public function test_the_cli_brief_names_what_each_hours_figure_counts(): void
    {
        $this->retainer(10, '2026-01-01');
        $this->entry($this->task(reportable: true), '2026-10-02 08:00:00', 60);
        $this->entry($this->task(reportable: false), '2026-10-03 08:00:00', 120);

        $this->artisan('crm:brief', ['client' => (string) $this->client->id])
            ->expectsOutputToContain('Hours logged this month: 3 (all time)')
            ->expectsOutputToContain('Hours bank (reportable, 2026-10): 1 used of 10 available (10 contracted, 0 carried), 9 left')
            ->expectsOutputToContain('Last closed month: none')
            ->assertSuccessful();
    }

    public function test_another_accounts_client_never_moves_these_figures(): void
    {
        $this->retainer(10, '2026-01-01');
        $this->report('2026-09', opening: 0, contracted: 10, actual: 4);
        $this->entry($this->task(reportable: true), '2026-10-02 08:00:00', 60);

        $other = Account::create(['name' => 'Other']);
        $stranger = $other->clients()->create(['name' => 'Acme', 'currency' => 'PLN']);
        $strangerProject = Project::create(['account_id' => $other->id, 'client_id' => $stranger->id, 'name' => 'Site']);
        $strangerTask = Task::create(['project_id' => $strangerProject->id, 'name' => 'Theirs', 'is_reportable' => true]);
        ClientRetainer::create(['account_id' => $other->id, 'client_id' => $stranger->id, 'monthly_hours' => 40, 'effective_from' => '2026-01-01']);
        ClientReport::create([
            'account_id' => $other->id, 'client_id' => $stranger->id, 'period_type' => 'month',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'contracted_hours' => 40,
            'actual_hours' => 1, 'opening_balance_hours' => 0, 'composer_key' => 'structured_list',
        ]);
        TimeEntry::create([
            'account_id' => $other->id, 'project_id' => $strangerProject->id, 'client_id' => $stranger->id,
            'task_id' => $strangerTask->id, 'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => '2026-10-02 09:00:00', 'end_time' => '2026-10-02 17:00:00', 'duration_minutes' => 480,
        ]);

        $overview = $this->overview();
        $this->assertSame(6.0, $overview['carried_hours']);
        $this->assertSame(10.0, $overview['contracted_hours']);
        $this->assertSame(1.0, $overview['month_hours']);
        $this->assertSame(6.0, $this->openingFor('2026-10'));
        $this->assertSame(1.0, $this->brief()['month']);
    }

    /**
     * The current month as the record screens read it.
     *
     * @return array<string, float>
     */
    private function overview(): array
    {
        $bank = app(HoursBank::class)->month($this->client->fresh(), LocalCalendar::currentMonth());

        return [
            'month_hours' => $bank->usedHours,
            'contracted_hours' => $bank->contractedHours ?? 0.0,
            'carried_hours' => $bank->openingBalanceHours,
            'available_hours' => $bank->availableHours(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function brief(): array
    {
        return app(ClientBrief::class)->for($this->client->fresh())['hours'];
    }

    private function contextFor(string $month): \App\Services\Reports\ReportContext
    {
        $start = Carbon::parse($month.'-01');

        return app(ReportDataAggregator::class)->aggregate($this->client->fresh(), $start, $start->copy()->endOfMonth(), 'month');
    }

    private function openingFor(string $month): float
    {
        return $this->contextFor($month)->openingBalanceHours;
    }

    private function retainer(float $hours, string $from, ?string $to = null, ?float $cap = null): ClientRetainer
    {
        return ClientRetainer::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'monthly_hours' => $hours,
            'rollover_cap_hours' => $cap,
            'effective_from' => $from,
            'effective_to' => $to,
        ]);
    }

    private function report(string $month, float $opening, ?float $contracted, float $actual, ?float $cap = null, string $status = ClientReport::STATUS_DRAFT, ?string $end = null, ?Client $client = null): ClientReport
    {
        $start = Carbon::parse($month.'-01');
        $client ??= $this->client;

        return ClientReport::create([
            'account_id' => $client->account_id,
            'client_id' => $client->id,
            'period_type' => ClientReport::PERIOD_MONTH,
            'status' => $status,
            'period_start' => $start->toDateString(),
            'period_end' => $end ?? $start->copy()->endOfMonth()->toDateString(),
            'contracted_hours' => $contracted,
            'actual_hours' => $actual,
            'opening_balance_hours' => $opening,
            'rollover_cap_hours' => $cap,
            'composer_key' => 'structured_list',
        ]);
    }

    private function task(bool $reportable): Task
    {
        return Task::create(['project_id' => $this->project->id, 'name' => $reportable ? 'Feature' : 'Upkeep', 'is_reportable' => $reportable]);
    }

    private function entry(?Task $task, string $startUtc, int $minutes): TimeEntry
    {
        $start = Carbon::parse($startUtc, 'UTC');

        return TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $task?->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => $start,
            'end_time' => $start->copy()->addMinutes($minutes),
            'duration_minutes' => $minutes,
        ]);
    }
}
