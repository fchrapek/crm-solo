<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgentAuditEvent;
use App\Models\Client;
use App\Models\ClientLifecycleEvent;
use App\Models\ClientReport;
use App\Models\ClientRetainer;
use App\Models\MonthCloseRun;
use App\Models\MonthCloseStep;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Agent\ClientBrief;
use App\Services\Clients\ClientJournal;
use App\Services\Clients\HoursBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ClientOverviewTest extends TestCase
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
        $this->travelTo(Carbon::parse('2026-01-10 10:00:00', 'UTC'));

        // Settled up to the running month, so these tests read the report flow; SettlementSurfacesTest covers a month left open.
        $this->account = Account::create(['name' => 'Acme', 'settings' => ['settlement_from' => '2026-10']]);
        $this->user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->client = $this->account->clients()->create(['name' => 'Pracownia Testowa', 'currency' => 'PLN']);
        $this->project = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Site']);

        $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function leavingTheClose(): array
    {
        return [
            'switched off' => [['include_in_month_close' => false]],
            'type cleared' => [['month_close_type' => null]],
        ];
    }

    public function test_only_the_overview_tab_computes_the_overview(): void
    {
        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/przeglad")
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/show')
                ->has('overview.report_due')
                ->has('overview.month_close')
                ->has('overview.doing_count')
                ->has('overview.journal')
                ->has('overview.hours')
            );

        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/dane")
            ->assertInertia(fn (Assert $page) => $page->missing('overview'));
    }

    public function test_a_client_with_nothing_waiting_has_empty_blocks(): void
    {
        $overview = $this->overview();

        $this->assertNull($overview['report_due']);
        $this->assertNull($overview['month_close']);
        $this->assertSame(0, $overview['doing_count']);
        $this->assertSame(['since' => '2026-09-28', 'since_today' => false, 'count' => 0], $overview['journal']);
        $this->assertNull($overview['hours'], 'a client without retainer evidence reads the order layout');
        $this->assertNotNull($overview['order']);
    }

    public function test_a_retainer_client_owes_last_months_report_until_one_exists(): void
    {
        $this->retainer(10);

        $this->assertSame(['period' => '2026-09', 'report_id' => null, 'status' => null], $this->overview()['report_due']);
    }

    public function test_an_unsent_report_for_last_month_is_waiting_with_its_state(): void
    {
        $this->retainer(10);
        $draft = $this->report('2026-09', ClientReport::STATUS_DRAFT);

        $this->assertSame(['period' => '2026-09', 'report_id' => $draft->id, 'status' => 'draft'], $this->overview()['report_due']);

        $draft->update(['status' => ClientReport::STATUS_FINALIZED]);
        $this->assertSame('finalized', $this->overview()['report_due']['status']);
    }

    public function test_a_sent_report_is_no_longer_waiting_and_the_hours_move_to_the_running_month(): void
    {
        $this->retainer(10);
        $this->report('2026-09', ClientReport::STATUS_SENT);

        $overview = $this->overview();
        $this->assertNull($overview['report_due']);
        $this->assertSame('2026-10', $overview['hours']['period']);
    }

    public function test_an_august_report_still_unsent_in_october_waits_before_september(): void
    {
        $this->retainer(10);
        $august = $this->report('2026-08', ClientReport::STATUS_DRAFT);
        $this->report('2026-09', ClientReport::STATUS_SENT);

        $overview = $this->overview();
        $this->assertSame(['period' => '2026-08', 'report_id' => $august->id, 'status' => 'draft'], $overview['report_due']);
        $this->assertSame('2026-10', $overview['hours']['period']);
    }

    public function test_a_finalized_report_beats_a_newer_draft_for_the_same_month(): void
    {
        $this->retainer(10);
        $finalized = $this->report('2026-09', ClientReport::STATUS_FINALIZED);
        $this->report('2026-09', ClientReport::STATUS_DRAFT);

        $this->assertSame(['period' => '2026-09', 'report_id' => $finalized->id, 'status' => 'finalized'], $this->overview()['report_due']);
    }

    public function test_a_month_settled_by_a_sent_report_is_not_owed_for_its_older_draft(): void
    {
        $this->retainer(10);
        $this->report('2026-08', ClientReport::STATUS_DRAFT);
        $this->report('2026-08', ClientReport::STATUS_SENT);

        $this->assertSame(['period' => '2026-09', 'report_id' => null, 'status' => null], $this->overview()['report_due']);
    }

    public function test_a_draft_for_the_running_month_is_not_waiting_yet(): void
    {
        $this->retainer(10);
        $this->report('2026-09', ClientReport::STATUS_SENT);
        $this->report('2026-10', ClientReport::STATUS_DRAFT);

        $this->assertNull($this->overview()['report_due']);
    }

    public function test_a_written_report_stays_waiting_whatever_the_report_mode(): void
    {
        $this->retainer(10);
        $draft = $this->report('2026-09', ClientReport::STATUS_FINALIZED);

        foreach ([MonthCloseRun::REPORT_MODE_SUMMARY_EMAIL, MonthCloseRun::REPORT_MODE_NONE] as $mode) {
            $this->client->update(['report_mode' => $mode]);
            $this->assertSame(['period' => '2026-09', 'report_id' => $draft->id, 'status' => 'finalized'], $this->overview()['report_due']);
        }
    }

    public function test_no_report_is_owed_without_a_retainer_or_when_the_client_gets_a_summary_email(): void
    {
        $this->assertNull($this->overview()['report_due']);

        $this->retainer(10);
        $this->client->update(['report_mode' => MonthCloseRun::REPORT_MODE_SUMMARY_EMAIL]);
        $this->assertNull($this->overview()['report_due']);
    }

    public function test_an_unsent_report_from_before_the_baseline_leaves_the_hours_on_the_active_month_as_lista_shows(): void
    {
        $this->retainer(10);
        $this->report('2026-09', ClientReport::STATUS_DRAFT);
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Feature', 'is_reportable' => true]);
        $this->entry($task, '2026-09-10 08:00:00', 90);
        $this->entry($task, '2026-10-02 08:00:00', 30);

        $overview = $this->overview();
        $this->assertSame('2026-09', $overview['report_due']['period']);
        $this->assertSame('2026-10', $overview['hours']['period']);
        $this->assertSame(0.5, $overview['hours']['used']);
        $this->assertSame('2026-10', $overview['settlement']['active_period']);

        $this->actingAs($this->user)->get('/clients')->assertInertia(fn (Assert $page) => $page
            ->where('clients.data.0.id', $this->client->id)
            ->where('clients.data.0.hours.period', $overview['hours']['period']));
    }

    public function test_the_hours_are_the_hours_banks_figures_for_that_month(): void
    {
        $this->account->update(['settings' => ['settlement_from' => '2026-09']]);
        $this->retainer(10, cap: 12);
        $this->report('2026-08', ClientReport::STATUS_SENT, opening: 4, contracted: 10, actual: 6, cap: 12);
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Feature', 'is_reportable' => true]);
        $this->entry($task, '2026-09-10 08:00:00', 600);
        $this->entry(Task::create(['project_id' => $this->project->id, 'name' => 'Upkeep', 'is_reportable' => false]), '2026-09-11 08:00:00', 60);

        $hours = $this->overview()['hours'];
        $month = app(HoursBank::class)->month($this->client->fresh(), '2026-09');

        $this->assertSame([...$month->toArray(), 'forfeited' => $month->forfeitedHours()], $hours);
        $this->assertSame(6.0, $hours['carried']);
        $this->assertSame(12.0, $hours['available']);
        $this->assertSame(4.0, $hours['forfeited']);
        $this->assertSame(10.0, $hours['used']);
        $this->assertSame(11.0, $hours['logged']);
        $this->assertSame(2.0, $hours['remaining']);
    }

    public function test_a_close_not_started_counts_the_steps_starting_it_would_seed(): void
    {
        $this->client->update(['month_close_type' => MonthCloseRun::TYPE_GIG, 'include_in_month_close' => true]);

        $this->assertSame(['period' => '2026-09', 'started' => false, 'done' => 0, 'total' => 3], $this->overview()['month_close']);
    }

    public function test_a_running_close_counts_done_and_skipped_steps_and_a_finished_one_is_gone(): void
    {
        $this->client->update(['month_close_type' => MonthCloseRun::TYPE_GIG, 'include_in_month_close' => true]);
        $run = MonthCloseRun::startFor($this->client->fresh(), '2026-09');
        $steps = $run->steps()->get();
        $steps[0]->update(['state' => MonthCloseStep::STATE_DONE]);
        $steps[1]->update(['state' => MonthCloseStep::STATE_SKIPPED]);
        MonthCloseRun::startFor($this->client->fresh(), '2026-10');

        $this->assertSame(['period' => '2026-09', 'started' => true, 'done' => 2, 'total' => 3], $this->overview()['month_close']);

        $steps[2]->update(['state' => MonthCloseStep::STATE_DONE]);
        $run->refreshStatusFromSteps();
        $this->assertNull($this->overview()['month_close']);
    }

    public function test_an_august_close_still_open_in_october_waits_before_septembers(): void
    {
        $this->client->update(['month_close_type' => MonthCloseRun::TYPE_GIG, 'include_in_month_close' => true]);
        $august = MonthCloseRun::startFor($this->client->fresh(), '2026-08');
        $august->steps()->first()->update(['state' => MonthCloseStep::STATE_DONE]);
        MonthCloseRun::startFor($this->client->fresh(), '2026-09');

        $this->assertSame(['period' => '2026-08', 'started' => true, 'done' => 1, 'total' => 3], $this->overview()['month_close']);
    }

    /** @param array<string, mixed> $leave */
    #[DataProvider('leavingTheClose')]
    public function test_an_open_close_stays_waiting_and_reachable_after_the_client_leaves_the_close(array $leave): void
    {
        $this->client->update(['month_close_type' => MonthCloseRun::TYPE_GIG, 'include_in_month_close' => true]);
        MonthCloseRun::startFor($this->client->fresh(), '2026-08');
        $this->client->update($leave);

        $this->assertSame('2026-08', $this->overview()['month_close']['period']);

        $this->actingAs($this->user)
            ->get('/month-close?period=2026-08')
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients', 1)
                ->where('clients.0.client.id', $this->client->id)
                ->where('clients.0.total', 3)
            );

        $this->actingAs($this->user)
            ->get("/month-close/{$this->client->id}?period=2026-08")
            ->assertInertia(fn (Assert $page) => $page
                ->where('client.type', MonthCloseRun::TYPE_GIG)
                ->has('client_steps', 3)
            );

        $this->actingAs($this->user)
            ->get('/month-close?period=2026-09')
            ->assertInertia(fn (Assert $page) => $page->has('clients', 0));
    }

    public function test_a_left_client_whose_close_is_completed_leaves_the_month_close_page(): void
    {
        $this->client->update(['month_close_type' => MonthCloseRun::TYPE_GIG, 'include_in_month_close' => true]);
        $run = MonthCloseRun::startFor($this->client->fresh(), '2026-08');
        $run->steps()->update(['state' => MonthCloseStep::STATE_DONE]);
        $run->refreshStatusFromSteps();
        $this->client->update(['include_in_month_close' => false]);

        $this->actingAs($this->user)
            ->get('/month-close?period=2026-08')
            ->assertInertia(fn (Assert $page) => $page->has('clients', 0));
    }

    public function test_with_last_months_close_completed_and_nothing_older_open_no_close_waits(): void
    {
        $this->client->update(['month_close_type' => MonthCloseRun::TYPE_GIG, 'include_in_month_close' => true]);
        $run = MonthCloseRun::startFor($this->client->fresh(), '2026-09');
        $run->steps()->update(['state' => MonthCloseStep::STATE_DONE]);
        $run->refreshStatusFromSteps();

        $this->assertNull($this->overview()['month_close']);
    }

    public function test_a_client_left_out_of_the_close_has_no_close_row(): void
    {
        $this->client->update(['month_close_type' => MonthCloseRun::TYPE_GIG, 'include_in_month_close' => false]);

        $this->assertNull($this->overview()['month_close']);
    }

    public function test_doing_counts_only_this_clients_open_tasks_on_doing(): void
    {
        Task::create(['project_id' => $this->project->id, 'name' => 'One', 'list_name' => 'Doing']);
        Task::create(['project_id' => $this->project->id, 'name' => 'Two', 'list_name' => 'Doing']);
        Task::create(['project_id' => $this->project->id, 'name' => 'Queued', 'list_name' => 'To-Do']);
        Task::create(['project_id' => $this->project->id, 'name' => 'Finished', 'list_name' => 'Doing', 'finished_at' => now()]);
        $other = $this->account->clients()->create(['name' => 'Other', 'currency' => 'PLN']);
        $otherProject = Project::create(['account_id' => $this->account->id, 'client_id' => $other->id, 'name' => 'Other site']);
        Task::create(['project_id' => $otherProject->id, 'name' => 'Theirs', 'list_name' => 'Doing']);

        $this->assertSame(2, $this->overview()['doing_count']);
    }

    public function test_new_journal_rows_start_at_local_midnight_seven_days_back(): void
    {
        $this->entry(null, '2026-09-27 22:30:00', 30);
        $this->entry(null, '2026-09-27 21:30:00', 30);
        $this->event('2026-09-27 21:59:00');
        $this->event('2026-10-04 12:00:00');

        $this->assertSame(['since' => '2026-09-28', 'since_today' => false, 'count' => 2], $this->overview()['journal']);
    }

    public function test_one_agent_session_is_one_journal_row_whatever_it_wrote(): void
    {
        $this->entry(null, '2026-10-01 08:00:00', 30, session: 'run-1');
        $this->entry(null, '2026-10-01 09:00:00', 30, session: 'run-1');
        $this->event('2026-10-01 09:30:00', session: 'run-1');
        $this->entry(null, '2026-10-02 08:00:00', 30);
        $this->event('2026-10-03 08:00:00');

        $this->assertSame(3, $this->overview()['journal']['count']);
    }

    public function test_the_journal_counts_from_the_exact_instant_it_is_given(): void
    {
        $this->entry(null, '2026-10-01 10:00:00', 30);
        $this->entry(null, '2026-10-01 09:59:00', 30);
        $this->event('2026-10-01 10:30:00');

        $rows = app(ClientJournal::class)->rowsSince($this->client, Carbon::parse('2026-10-01 12:00:00', 'Europe/Warsaw'));

        $this->assertSame(['event', 'entry'], $rows->pluck('kind')->all());
    }

    public function test_another_accounts_records_never_move_the_overview(): void
    {
        $this->retainer(10);
        $other = Account::create(['name' => 'Other']);
        $stranger = $other->clients()->create(['name' => 'Pracownia Testowa', 'currency' => 'PLN', 'month_close_type' => 'gig', 'include_in_month_close' => true, 'report_mode' => 'report']);
        $strangerProject = Project::create(['account_id' => $other->id, 'client_id' => $stranger->id, 'name' => 'Site']);
        $strangerTask = Task::create(['project_id' => $strangerProject->id, 'name' => 'Theirs', 'list_name' => 'Doing', 'is_reportable' => true]);
        ClientReport::create([
            'account_id' => $other->id, 'client_id' => $stranger->id, 'period_type' => 'month', 'status' => 'sent',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'contracted_hours' => 40,
            'actual_hours' => 1, 'opening_balance_hours' => 0, 'composer_key' => 'structured_list',
        ]);
        TimeEntry::create([
            'account_id' => $other->id, 'project_id' => $strangerProject->id, 'client_id' => $stranger->id,
            'task_id' => $strangerTask->id, 'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => '2026-09-10 09:00:00', 'end_time' => '2026-09-10 17:00:00', 'duration_minutes' => 480,
        ]);
        MonthCloseRun::startFor($stranger, '2026-09');

        $overview = $this->overview();
        $this->assertSame(['period' => '2026-09', 'report_id' => null, 'status' => null], $overview['report_due']);
        $this->assertNull($overview['month_close']);
        $this->assertSame(0, $overview['doing_count']);
        $this->assertSame(0, $overview['journal']['count']);
        $this->assertSame(0.0, $overview['hours']['used']);

        $this->actingAs($this->user)->get("/clients/{$stranger->id}/przeglad")->assertNotFound();
    }

    public function test_the_agent_context_is_the_brief_an_agent_gets(): void
    {
        $this->retainer(10);
        Task::create(['project_id' => $this->project->id, 'name' => 'Open one', 'list_name' => 'Doing']);

        $response = $this->actingAs($this->user)
            ->getJson("/clients/{$this->client->id}/agent-context")
            ->assertOk();

        $context = json_decode((string) $response->json('context'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(json_decode((string) json_encode(app(ClientBrief::class)->for($this->client->fresh())), true), $context);
        $this->assertSame('Open one', $context['open_tasks'][0]['name']);
    }

    public function test_copying_the_agent_context_writes_nothing(): void
    {
        $this->entry(null, '2026-10-01 08:00:00', 30);
        $before = $this->tableCounts();

        $this->actingAs($this->user)->getJson("/clients/{$this->client->id}/agent-context")->assertOk();

        $this->assertSame($before, $this->tableCounts());
        $this->assertSame(0, AgentAuditEvent::query()->count());
        $this->actingAs($this->user)->postJson("/clients/{$this->client->id}/agent-context")->assertStatus(405);
    }

    public function test_another_accounts_client_has_no_agent_context(): void
    {
        $other = Account::create(['name' => 'Other']);
        $stranger = $other->clients()->create(['name' => 'Other Co', 'currency' => 'PLN']);

        $this->actingAs($this->user)->getJson("/clients/{$stranger->id}/agent-context")->assertNotFound();
    }

    public function test_a_guest_gets_no_agent_context(): void
    {
        $this->get("/clients/{$this->client->id}/agent-context")->assertRedirect('/login');
    }

    /**
     * @return array<string, mixed>
     */
    private function overview(): array
    {
        $response = $this->actingAs($this->user)->get("/clients/{$this->client->id}/przeglad");
        $response->assertOk();

        return $response->viewData('page')['props']['overview'];
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        return collect(['clients', 'tasks', 'time_entries', 'client_lifecycle_events', 'client_reports', 'month_close_runs', 'agent_audit_events'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
            ->all();
    }

    private function retainer(float $hours, ?float $cap = null): ClientRetainer
    {
        return ClientRetainer::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'monthly_hours' => $hours,
            'rollover_cap_hours' => $cap,
            'effective_from' => '2026-01-01',
        ]);
    }

    private function report(string $month, string $status, float $opening = 0, float $contracted = 10, float $actual = 0, ?float $cap = null): ClientReport
    {
        $start = Carbon::parse($month.'-01');

        return ClientReport::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'period_type' => ClientReport::PERIOD_MONTH,
            'status' => $status,
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'contracted_hours' => $contracted,
            'actual_hours' => $actual,
            'opening_balance_hours' => $opening,
            'rollover_cap_hours' => $cap,
            'composer_key' => 'structured_list',
        ]);
    }

    private function entry(?Task $task, string $startUtc, int $minutes, ?string $session = null): TimeEntry
    {
        $start = Carbon::parse($startUtc, 'UTC');
        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $task?->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => $start,
            'end_time' => $start->copy()->addMinutes($minutes),
            'duration_minutes' => $minutes,
        ]);

        if ($session !== null) {
            $entry->forceFill(['actor_via' => 'cli', 'actor_session_id' => $session])->save();
        }

        return $entry;
    }

    private function event(string $atUtc, ?string $session = null): ClientLifecycleEvent
    {
        $event = ClientLifecycleEvent::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'user_id' => $this->user->id,
            'from_stage' => 'active',
            'to_stage' => 'active',
            'note' => 'Call about the shop',
            'created_at' => Carbon::parse($atUtc, 'UTC'),
        ]);

        if ($session !== null) {
            $event->forceFill(['actor_via' => 'cli', 'actor_session_id' => $session])->save();
        }

        return $event;
    }
}
