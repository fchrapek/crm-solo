<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Models\ClientBilledMonth;
use App\Models\ClientMonthClosure;
use App\Models\ClientReport;
use App\Models\ClientReportRevision;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskBilledMonth;
use App\Models\TimeEntry;
use App\Services\Clients\BilledTime;
use App\Services\Clients\BilledTimeResult;
use App\Services\Clients\HoursBank;
use App\Services\Clients\HoursBankMonth;
use App\Services\Clients\MonthSettlement;
use App\Services\Clients\SettlementOutcome;
use App\Services\Reports\ReportWriter;
use App\Services\Time\PeriodSettled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BilledTimeTest extends TestCase
{
    use RefreshDatabase;
    use SettlementFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlement();
        $this->retainer(6, '2026-01-01');
    }

    public function test_every_client_bills_its_real_time_until_a_figure_is_stated_and_an_old_month_total_no_longer_counts(): void
    {
        $this->entry('2026-09-10 08:00:00', 61);
        $this->entry('2026-09-11 08:00:00', 40, reportable: false);
        ClientBilledMonth::create(['client_id' => $this->client->id, 'period' => '2026-09', 'billed_minutes' => 600, 'note' => 'Stara korekta']);

        $month = app(HoursBank::class)->month($this->client, '2026-09');
        $this->assertSame(1.02, $month->usedHours);
        $this->assertSame(1.68, $month->loggedHours);
        $this->assertSame(HoursBankMonth::SOURCE_BILLED, $month->source);
        $this->assertSame(['real' => 1.02, 'billed' => 1.02, 'tasks' => [['task_id' => $this->task->id, 'real' => 1.02, 'stated' => null, 'billed' => 1.02]], 'note' => 'Stara korekta'], $month->billed?->toArray());

        $snapshot = app(MonthSettlement::class)->preview($this->client, '2026-09')['snapshot'];
        $this->assertSame('billed', $snapshot['source']);
        $this->assertSame(['real' => 1.02, 'billed' => 1.02, 'tasks' => [['task_id' => $this->task->id, 'real' => 1.02, 'stated' => null, 'billed' => 1.02]]], $snapshot['billed_time']);

        $this->assertSame(1.02, (float) $this->generate('2026-09')->actual_hours);
    }

    public function test_a_task_figure_replaces_its_real_time_and_zero_is_a_figure(): void
    {
        $this->entry('2026-09-10 08:00:00', 200);

        $before = $this->entryRows();
        $this->assertSame(BilledTimeResult::APPLIED, $this->billed()->setTask($this->task, '2026-09', 2.25, 'Rabat', $this->user)->outcome);
        $this->assertSame(2.25, app(HoursBank::class)->month($this->client, '2026-09')->usedHours);

        $this->billed()->setTask($this->task, '2026-09', 0, null, $this->user);
        $month = app(HoursBank::class)->month($this->client, '2026-09');
        $this->assertSame(0.0, $month->usedHours);
        $this->assertSame(6.0, $month->closingBalanceHours());
        $this->assertSame('Rabat', TaskBilledMonth::query()->sole()->note);
        $this->assertSame($this->user->id, TaskBilledMonth::query()->sole()->updated_by_user_id);
        $this->assertSame(0, ClientBilledMonth::query()->count());

        $this->assertSame(BilledTimeResult::REFUSED, $this->billed()->setTask($this->task, '2026-09', -1)->outcome);
        $this->assertSame($before, $this->entryRows());
    }

    public function test_the_month_is_the_sum_of_its_tasks_and_the_most_billed_task_comes_first(): void
    {
        $this->entry('2026-09-10 08:00:00', 61);
        $other = Task::create(['project_id' => $this->project->id, 'name' => 'Poprawki', 'is_reportable' => true]);
        $this->entryOn($other, '2026-09-11 08:00:00', 10);

        $this->assertSame(1.18, app(HoursBank::class)->month($this->client, '2026-09')->usedHours);

        $before = $this->entryRows();
        $this->billed()->setTask($other, '2026-09', 3, 'Testy ręczne');
        $figures = $this->billed()->figures($this->client, '2026-09');
        $this->assertSame(4.02, $figures['billed']);
        $this->assertSame([
            ['task_id' => $other->id, 'task' => 'Poprawki', 'project' => $this->project->name, 'real' => 0.17, 'stated' => 3.0, 'billed' => 3.0, 'note' => 'Testy ręczne'],
            ['task_id' => $this->task->id, 'task' => $this->task->name, 'project' => $this->project->name, 'real' => 1.02, 'stated' => null, 'billed' => 1.02, 'note' => null],
        ], $figures['tasks']);
        $this->assertSame($before, $this->entryRows());
    }

    public function test_a_task_figure_counts_without_time_of_its_own_in_the_month(): void
    {
        $this->entry('2026-09-10 08:00:00', 30);
        $other = Task::create(['project_id' => $this->project->id, 'name' => 'Konsultacja', 'is_reportable' => true]);

        $before = $this->entryRows();
        $this->billed()->setTask($other, '2026-09', 1);

        $this->assertSame(1.5, app(HoursBank::class)->month($this->client, '2026-09')->usedHours);
        $this->assertSame($before, $this->entryRows());
    }

    public function test_a_task_that_is_not_reportable_gets_no_figure(): void
    {
        $this->task->update(['is_reportable' => false]);

        $result = $this->billed()->setTask($this->task, '2026-09', 3);

        $this->assertSame(BilledTime::CODE_NOT_REPORTABLE, $result->code);
        $this->assertSame(0, TaskBilledMonth::query()->count());
    }

    public function test_a_figure_written_before_a_task_turned_not_reportable_stops_counting(): void
    {
        $this->entry('2026-09-10 08:00:00', 30);
        $this->billed()->setTask($this->task, '2026-09', 3);
        $this->task->update(['is_reportable' => false]);

        $this->assertSame(0.0, app(HoursBank::class)->month($this->client, '2026-09')->usedHours);
    }

    public function test_without_a_month_a_task_figure_goes_to_the_month_it_was_last_worked_in(): void
    {
        $this->entry('2026-09-30 22:30:00', 30);

        $this->billed()->setTask($this->task, null, 2);

        $this->assertSame('2026-10', TaskBilledMonth::query()->sole()->period);
        $this->assertSame('2026-10', BilledTime::taskPeriod($this->task));
    }

    public function test_clearing_a_task_figure_brings_back_its_real_time_and_drops_the_note(): void
    {
        $this->entry('2026-09-10 08:00:00', 70);
        $this->billed()->setTask($this->task, '2026-09', 4, 'Na prośbę klienta');

        $before = $this->entryRows();
        $result = $this->billed()->setTask($this->task, '2026-09', null);

        $this->assertSame('Task billed: 1.17 h, September 2026 in total: 1.17 h.', $result->message);
        $this->assertSame(0, TaskBilledMonth::query()->count());
        $this->assertSame(1.17, app(HoursBank::class)->month($this->client, '2026-09')->usedHours);
        $this->assertSame(BilledTimeResult::UNCHANGED, $this->billed()->setTask($this->task, '2026-09', null)->outcome);
        $this->assertSame($before, $this->entryRows());
    }

    public function test_a_draft_report_follows_the_billed_hours_without_regenerating(): void
    {
        $this->entry('2026-09-10 08:00:00', 100);
        $report = $this->generate('2026-09');
        $this->assertSame(1.67, (float) $report->actual_hours);
        $later = $this->report('2026-10', 4.0, 6, 1);

        $before = $this->entryRows();
        $result = $this->billed()->setTask($this->task, '2026-09', 3.5, null, $this->user);

        $fresh = $report->fresh();
        $this->assertSame(3.5, (float) $fresh->actual_hours);
        $this->assertStringContainsString('Used in the month: −3.5h', (string) $fresh->body_markdown);
        $this->assertStringContainsString('Opening balance for next month: +2.5h', (string) $fresh->body_markdown);
        $this->assertSame(ClientReportRevision::REASON_BILLED_HOURS, $fresh->revisions()->first()->reason);
        $this->assertSame(1.67, (float) $fresh->revisions()->first()->actual_hours_before);
        $this->assertTrue($later->fresh()->needs_recalculation);
        $this->assertSame('2026-10', $result->recalculate);
        $this->assertSame(3.5, app(HoursBank::class)->month($this->client, '2026-09')->usedHours);
        $this->assertSame($before, $this->entryRows());
    }

    public function test_report_create_hours_must_be_the_task_sum_and_a_regenerate_follows_the_tasks(): void
    {
        $this->entry('2026-09-10 08:00:00', 100);

        $before = $this->entryRows();
        $start = Carbon::parse('2026-09-01');
        $refused = app(ReportWriter::class)->generate($this->client->fresh(), $start, $start->copy()->endOfMonth(), 'en', 'structured_list', 4.0, $this->user);
        $this->assertTrue($refused->isRefused());
        $this->assertSame('September 2026 bills 1.67 h, the sum of its tasks; set the task figures to change it.', $refused->message);

        $report = $this->generate('2026-09', 1.67);
        $this->assertSame(1.67, (float) $report->actual_hours);
        $this->assertSame(0, ClientBilledMonth::query()->count());

        $this->billed()->setTask($this->task, '2026-09', 5);
        $this->assertSame(5.0, (float) $report->fresh()->actual_hours);
        app(ReportWriter::class)->regenerate($report->fresh(), 'en', 'structured_list');
        $this->assertSame(5.0, (float) $report->fresh()->actual_hours);
        $this->assertSame($before, $this->entryRows());
    }

    public function test_a_closed_month_a_settled_report_and_a_later_sent_report_refuse_the_write(): void
    {
        $this->entry('2026-09-10 08:00:00', 100);
        $closure = $this->closure('2026-09', 0, 6, 2);

        $closed = $this->billed()->setTask($this->task, '2026-09', 3);
        $this->assertSame(BilledTime::CODE_PERIOD_SETTLED, $closed->code);
        $this->assertSame('Reopen September 2026 to change the figures.', $closed->message);
        $closure->delete();

        $report = $this->report('2026-09', 0, 6, 2, ClientReport::STATUS_FINALIZED);
        $this->assertSame(BilledTime::CODE_NOT_A_DRAFT, $this->billed()->setTask($this->task, '2026-09', 3)->code);
        $report->delete();

        $this->report('2026-10', 4, 6, 1, ClientReport::STATUS_SENT);
        $this->assertSame(BilledTime::CODE_PERIOD_SETTLED, $this->billed()->setTask($this->task, '2026-09', 3)->code);
        $this->assertSame(BilledTime::CODE_PERIOD_SETTLED, $this->billed()->setTask($this->task, '2026-09', null)->code);

        $this->assertSame(0, ClientBilledMonth::query()->count() + TaskBilledMonth::query()->count());
    }

    public function test_an_earlier_flagged_draft_refuses_with_its_own_code(): void
    {
        $this->report('2026-08', 0, 6, 1)->update(['needs_recalculation' => true]);

        $this->assertSame(BilledTime::CODE_REFUSED, $this->billed()->setTask($this->task, '2026-09', 3)->code);
    }

    public function test_closing_a_month_freezes_both_values_with_the_billed_source(): void
    {
        $this->entry('2026-09-10 08:00:00', 100);
        $this->billed()->setTask($this->task, '2026-09', 2.5);

        $preview = app(MonthSettlement::class)->preview($this->client, '2026-09');
        $result = app(MonthSettlement::class)->close($this->client, '2026-09', $preview['hash'], $this->user);
        $this->assertSame(SettlementOutcome::Applied, $result->outcome);

        $snapshot = ClientMonthClosure::query()->sole()->snapshot;
        $this->assertSame(2, $snapshot['schema_version']);
        $this->assertSame('billed', $snapshot['source']);
        $this->assertEquals(2.5, $snapshot['used']);
        $this->assertEquals(1.67, $snapshot['entries']);
        $this->assertEquals(['real' => 1.67, 'billed' => 2.5, 'tasks' => [['task_id' => $this->task->id, 'real' => 1.67, 'stated' => 2.5, 'billed' => 2.5]]], $snapshot['billed_time']);

        $this->entry('2026-09-12 08:00:00', 600);
        $closed = app(HoursBank::class)->month($this->client, '2026-09');
        $this->assertSame(2.5, $closed->usedHours);
        $this->assertSame(1.67, $closed->billed?->realHours());
        $this->assertSame(1.67, $this->billed()->figures($this->client, '2026-09')['real']);
    }

    public function test_the_figures_carry_both_values_and_why_the_month_cannot_change(): void
    {
        $this->entry('2026-09-10 08:00:00', 100);
        $this->billed()->setTask($this->task, '2026-09', 2.5, 'Rabat');
        $this->report('2026-10', 3.5, 6, 1, ClientReport::STATUS_SENT);

        $figures = $this->billed()->figures($this->client, '2026-09');

        $this->assertSame(1.67, $figures['real']);
        $this->assertSame(2.5, $figures['billed']);
        $this->assertSame([['task_id' => $this->task->id, 'task' => $this->task->name, 'project' => $this->project->name, 'real' => 1.67, 'stated' => 2.5, 'billed' => 2.5, 'note' => 'Rabat']], $figures['tasks']);
        $this->assertArrayNotHasKey('enabled', $figures);
        $this->assertArrayNotHasKey('rounding_minutes', $figures);
        $this->assertSame(6.0, $figures['available']);
        $this->assertSame(3.5, $figures['closing']);
        $this->assertSame(BilledTime::CODE_PERIOD_SETTLED, $figures['refusal_code']);
    }

    public function test_a_governing_report_or_closure_is_the_billed_figure_everywhere(): void
    {
        $this->entry('2026-09-10 08:00:00', 100);
        $this->billed()->setTask($this->task, '2026-09', 2);
        $this->report('2026-09', 0, 6, 2, ClientReport::STATUS_SENT);
        $this->entry('2026-09-11 08:00:00', 60);

        $figures = $this->billed()->figures($this->client, '2026-09');
        $this->assertSame($figures['used'], $figures['billed']);
        $this->assertSame(2.0, $figures['billed']);
        $this->assertSame(2.67, $figures['real']);

        $preview = app(MonthSettlement::class)->preview($this->client, '2026-09');
        app(MonthSettlement::class)->close($this->client, '2026-09', $preview['hash'], $this->user);
        $snapshot = ClientMonthClosure::query()->sole()->snapshot;
        $this->assertEquals(2, $snapshot['used']);
        $this->assertEquals(2, $snapshot['billed_time']['billed']);
        $this->assertSame(2.0, app(HoursBank::class)->month($this->client, '2026-09')->billed?->billedHours());
    }

    public function test_billed_hours_into_a_body_without_one_billing_summary_are_refused_and_nothing_is_saved(): void
    {
        $this->entry('2026-09-10 08:00:00', 100);
        $draft = $this->report('2026-09', 0, 6, 1.67);

        $set = $this->billed()->setTask($this->task, '2026-09', 3);
        $this->assertSame(BilledTimeResult::REFUSED, $set->outcome);
        $this->assertStringContainsString('no billing summary', $set->message);
        $this->assertSame(0, TaskBilledMonth::query()->count());
        $this->assertSame(1.67, (float) $draft->fresh()->actual_hours);
        $this->assertSame(0, $draft->fresh()->revisions()->count());
    }

    public function test_a_month_closed_before_billed_time_reads_its_entries_as_real_and_its_used_as_billed(): void
    {
        $closure = $this->closure('2026-09', 0, 6, 2);
        $closure->update(['snapshot' => [...$closure->snapshot, 'entries' => 1.67]]);

        $month = app(HoursBank::class)->month($this->client, '2026-09');
        $this->assertSame(1.67, $month->billed?->realHours());
        $this->assertSame(2.0, $month->billed?->billedHours());
    }

    public function test_a_closure_frozen_with_rounding_keeps_its_figures(): void
    {
        $closure = $this->closure('2026-09', 0, 6, 1.5);
        $closure->update(['snapshot' => [...$closure->snapshot, 'billed_time' => ['real' => 1.02, 'rounding_minutes' => 30, 'rounded' => 1.5, 'override' => null, 'billed' => 1.5,
            'tasks' => [['task_id' => $this->task->id, 'real' => 1.02, 'stated' => null, 'billed' => 1.5]]]]]);
        $this->entry('2026-09-12 08:00:00', 600);

        $month = app(HoursBank::class)->month($this->client, '2026-09');
        $this->assertSame(1.5, $month->usedHours);
        $this->assertSame(1.5, $month->billed?->billedHours());
        $this->assertSame(1.02, $month->billed?->realHours());
        $this->assertSame([1.02, null, 1.5], [
            $this->billed()->figures($this->client, '2026-09')['tasks'][0]['real'],
            $this->billed()->figures($this->client, '2026-09')['tasks'][0]['stated'],
            $this->billed()->figures($this->client, '2026-09')['tasks'][0]['billed'],
        ]);
    }

    public function test_a_finalized_report_not_yet_closed_refuses_turning_its_task_not_reportable(): void
    {
        $this->entry('2026-09-10 08:00:00', 60);
        $report = $this->withSummary($this->generate('2026-09'));
        app(\App\Services\Reports\ReportStatusTransition::class)->finalize($report, $this->user);

        $before = $this->entryRows();
        $this->actingAs($this->user)->patchJson("/tasks/{$this->task->id}/reportable", ['is_reportable' => false])
            ->assertStatus(422)
            ->assertJsonPath('errors.is_reportable.0', 'This task counts in the finalized or sent report for September 2026. Reopen the report to change whether it counts.');

        $this->assertTrue($this->task->fresh()->is_reportable);
        $this->assertNull($this->billed()->taskSumRefusal($this->client, $report->fresh()));
        $this->assertSame($before, $this->entryRows());

        $preview = app(MonthSettlement::class)->preview($this->client, '2026-09');
        app(MonthSettlement::class)->close($this->client, '2026-09', $preview['hash'], $this->user);
        $this->actingAs($this->user)->patchJson("/tasks/{$this->task->id}/reportable", ['is_reportable' => false])->assertOk();
        $this->assertSame($before, $this->entryRows());
    }

    public function test_a_recalculating_regenerate_makes_a_hand_set_opening_the_banks_again(): void
    {
        $this->entry('2026-09-10 08:00:00', 100);
        $this->withSummary($this->report('2026-08', 0, 6, 3, ClientReport::STATUS_FINALIZED));
        $this->withSummary($this->report('2026-09', 3, 6, 1.67));
        $october = $this->withSummary($this->report('2026-10', 0, 6, 1));
        app(ReportWriter::class)->updateOpening($october, 1.0, null, null, $this->user);
        $this->assertNotNull($october->fresh()->opening_set_at);

        app(ReportWriter::class)->regenerate($october->fresh(), 'en', 'structured_list', recalculate: true);
        $this->assertNull($october->fresh()->opening_set_at);
        $this->assertSame(7.33, (float) $october->fresh()->opening_balance_hours);
    }

    public function test_a_stale_task_read_before_a_move_bills_the_client_it_belongs_to_now(): void
    {
        $other = $this->account->clients()->create(['name' => 'Kawiarnia', 'currency' => 'PLN']);
        $project = Project::create(['account_id' => $this->account->id, 'client_id' => $other->id, 'name' => 'Sklep']);
        $this->report('2026-09', 0, null, 0, ClientReport::STATUS_FINALIZED, client: $other);
        $stale = Task::query()->find($this->task->id);
        $this->task->update(['project_id' => $project->id]);

        $result = $this->billed()->setTask($stale, '2026-09', 3);

        $this->assertSame(BilledTime::CODE_NOT_A_DRAFT, $result->code);
        $this->assertSame(0, TaskBilledMonth::query()->count());
    }

    public function test_a_figure_stays_with_the_client_it_was_billed_to_when_the_task_moves_without_its_time(): void
    {
        $this->entry('2026-09-10 08:00:00', 60);
        $before = $this->entryRows();
        $this->billed()->setTask($this->task, '2026-09', 3);
        $other = $this->account->clients()->create(['name' => 'Kawiarnia', 'currency' => 'PLN']);
        $this->task->update(['project_id' => Project::create(['account_id' => $this->account->id, 'client_id' => $other->id, 'name' => 'Sklep'])->id]);

        $this->assertSame(3.0, app(HoursBank::class)->month($this->client, '2026-09')->usedHours);
        $this->assertSame(0.0, app(HoursBank::class)->month($other, '2026-09')->usedHours);
        $this->assertSame($before, $this->entryRows());
    }

    public function test_turning_a_task_not_reportable_flags_the_drafts_it_counted_in_so_the_month_cannot_close_on_them(): void
    {
        $this->entry('2026-09-10 08:00:00', 60);
        $this->billed()->setTask($this->task, '2026-09', 3);
        $september = $this->withSummary($this->generate('2026-09'));
        $october = $this->report('2026-10', 3, 6, 0);

        $before = $this->entryRows();
        $this->task->update(['is_reportable' => false]);

        $this->assertTrue($september->fresh()->needs_recalculation);
        $this->assertTrue($october->fresh()->needs_recalculation);
        $this->assertNotNull(app(MonthSettlement::class)->preview($this->client, '2026-09')['refusal']);
        $this->assertSame($before, $this->entryRows());
    }

    public function test_deleting_a_task_drops_its_open_figures_and_flags_the_draft(): void
    {
        $other = Task::create(['project_id' => $this->project->id, 'name' => 'Konsultacja', 'is_reportable' => true]);
        $this->billed()->setTask($other, '2026-09', 3);
        $draft = $this->withSummary($this->generate('2026-09'));

        $other->delete();

        $this->assertSame(0, TaskBilledMonth::query()->count());
        $this->assertTrue($draft->fresh()->needs_recalculation);
    }

    public function test_a_figure_in_a_closed_month_refuses_the_task_delete(): void
    {
        $other = Task::create(['project_id' => $this->project->id, 'name' => 'Konsultacja', 'is_reportable' => true]);
        $this->billed()->setTask($other, '2026-09', 3);
        $this->closure('2026-09', 0, 6, 3);

        $this->expectException(PeriodSettled::class);
        try {
            $other->delete();
        } finally {
            $this->assertSame(1, TaskBilledMonth::query()->count());
            $this->assertNotNull(Task::query()->find($other->id));
        }
    }

    public function test_a_running_timer_makes_its_month_the_default_for_a_task_figure(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'UTC'));
        $this->entry('2026-09-10 08:00:00', 60);
        $this->entry('2026-10-20 09:00:00', 0, running: true);

        $this->assertSame('2026-10', BilledTime::taskPeriod($this->task));
    }

    public function test_a_task_billed_without_time_in_the_month_is_on_the_report_work_list(): void
    {
        $other = Task::create(['project_id' => $this->project->id, 'name' => 'Konsultacja', 'is_reportable' => true]);
        $this->billed()->setTask($other, '2026-09', 3);

        $start = Carbon::parse('2026-09-01');
        $context = app(\App\Services\Reports\ReportDataAggregator::class)->aggregate($this->client->fresh(), $start, $start->copy()->endOfMonth(), ClientReport::PERIOD_MONTH, 'en');

        $this->assertContains($other->id, $context->tasks->pluck('id')->all());
    }

    public function test_a_month_cannot_close_or_finalize_while_its_report_differs_from_its_task_sum(): void
    {
        $this->entry('2026-09-10 08:00:00', 120);
        $report = $this->withSummary($this->generate('2026-09'));
        $this->entry('2026-09-11 08:00:00', 60);

        $before = $this->entryRows();
        $refusal = 'The report for September 2026 bills 2.0 h, but its tasks add up to 3.0 h: change the task figures or regenerate the report first.';
        $this->assertSame($refusal, app(MonthSettlement::class)->preview($this->client, '2026-09')['refusal']);
        $this->assertSame($refusal, app(\App\Services\Reports\ReportStatusTransition::class)->finalize($report, $this->user)->message);

        app(ReportWriter::class)->regenerate($report->fresh(), 'en', 'structured_list');
        $this->assertSame(3.0, (float) $report->fresh()->actual_hours);
        $this->assertNull(app(MonthSettlement::class)->preview($this->client, '2026-09')['refusal']);
        $this->assertSame($before, $this->entryRows());
    }

    public function test_a_figure_never_lands_on_another_client_when_the_task_moves_between_the_checks(): void
    {
        $this->entry('2026-09-10 08:00:00', 60);
        $other = $this->account->clients()->create(['name' => 'Kawiarnia', 'currency' => 'PLN']);
        $project = Project::create(['account_id' => $this->account->id, 'client_id' => $other->id, 'name' => 'Sklep']);
        $moved = false;
        DB::listen(function ($query) use (&$moved, $project): void {
            if (! $moved && str_contains($query->sql, 'select "client_id" from "projects"')) {
                $moved = true;
                DB::table('tasks')->where('id', $this->task->id)->update(['project_id' => $project->id]);
            }
        });

        $before = $this->entryRows();
        $result = $this->billed()->setTask($this->task, '2026-09', 3, null, $this->user);

        $this->assertSame(BilledTimeResult::REFUSED, $result->outcome);
        $this->assertSame(0, TaskBilledMonth::query()->count());
        $this->assertSame($before, $this->entryRows());
    }

    public function test_a_task_with_time_on_no_client_can_change_its_reportable_flag(): void
    {
        $inbox = Project::create(['account_id' => $this->account->id, 'client_id' => null, 'name' => 'Inbox']);
        $loose = Task::create(['project_id' => $inbox->id, 'name' => 'Luźne', 'is_reportable' => false]);
        TimeEntry::create(['account_id' => $this->account->id, 'project_id' => $inbox->id, 'client_id' => null, 'task_id' => $loose->id, 'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => Carbon::parse('2026-09-10 08:00:00', 'UTC'), 'end_time' => Carbon::parse('2026-09-10 09:00:00', 'UTC'), 'duration_minutes' => 60]);

        $loose->update(['is_reportable' => true]);

        $this->assertTrue($loose->fresh()->is_reportable);
    }

    public function test_a_closed_month_s_report_is_measured_by_its_closure_not_the_live_tasks(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'UTC'));
        $this->entry('2026-09-10 08:00:00', 61);
        $report = $this->withSummary($this->generate('2026-09'));
        app(\App\Services\Reports\ReportStatusTransition::class)->finalize($report, $this->user);
        $preview = app(MonthSettlement::class)->preview($this->client, '2026-09');
        app(MonthSettlement::class)->close($this->client, '2026-09', $preview['hash'], $this->user);
        $this->entry('2026-09-12 08:00:00', 60);

        $this->assertNull($this->billed()->taskSumRefusal($this->client->fresh(), $report->fresh()));
    }

    public function test_the_task_page_reportable_switch_flags_the_drafts_through_the_task_writer(): void
    {
        $this->entry('2026-09-10 08:00:00', 60);
        $draft = $this->withSummary($this->generate('2026-09'));

        $before = $this->entryRows();
        $this->actingAs($this->user)->patchJson("/tasks/{$this->task->id}/reportable", ['is_reportable' => false])->assertOk();

        $this->assertFalse($this->task->fresh()->is_reportable);
        $this->assertTrue($draft->fresh()->needs_recalculation);
        $this->assertSame($before, $this->entryRows());
    }

    private function withSummary(ClientReport $report): ClientReport
    {
        $report->update(['body_markdown' => \App\Services\Reports\BillingSummary::enforce(
            (string) $report->body_markdown,
            (float) $report->opening_balance_hours,
            (float) $report->contracted_hours,
            (float) $report->actual_hours,
            null,
            'en',
        )]);

        return $report->fresh();
    }

    private function billed(): BilledTime
    {
        return app(BilledTime::class);
    }

    private function generate(string $period, ?float $hours = null): ClientReport
    {
        $start = Carbon::parse($period.'-01');

        return app(ReportWriter::class)->generate($this->client->fresh(), $start, $start->copy()->endOfMonth(), 'en', 'structured_list', $hours, $this->user)->report;
    }

    private function entryOn(Task $task, string $startUtc, int $minutes): void
    {
        $start = Carbon::parse($startUtc, 'UTC');
        TimeEntry::create([
            'account_id' => $this->account->id, 'project_id' => $this->project->id, 'client_id' => $this->client->id, 'task_id' => $task->id,
            'source' => TimeEntry::SOURCE_MANUAL, 'start_time' => $start, 'end_time' => $start->copy()->addMinutes($minutes), 'duration_minutes' => $minutes,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function entryRows(): array
    {
        return DB::table('time_entries')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }
}
