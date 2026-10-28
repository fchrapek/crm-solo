<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Agent\ClientBrief;
use App\Services\Reports\ReportStatusTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ReportStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Acme']);
        $this->user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->client = $this->account->clients()->create(['name' => 'Acme', 'currency' => 'PLN']);
    }

    public function test_a_draft_is_finalized_then_marked_as_sent_with_a_revision_for_each_step(): void
    {
        $report = $this->report(ClientReport::STATUS_DRAFT);

        $this->travelTo(Carbon::parse('2026-10-02 09:15:00', 'UTC'));
        $this->transition($report, 'finalize')->assertSessionHas('success', 'Report finalized.');

        $this->travelTo(Carbon::parse('2026-10-03 14:40:00', 'UTC'));
        $this->transition($report, 'mark-sent')->assertSessionHas('success', 'Report marked as sent.');

        $fresh = $report->fresh();
        $this->assertSame(ClientReport::STATUS_SENT, $fresh->status);
        $this->assertSame('2026-10-02 09:15:00', $fresh->finalized_at->toDateTimeString());
        $this->assertSame('2026-10-03 14:40:00', $fresh->sent_at->toDateTimeString());

        $revisions = $fresh->revisions;
        $this->assertSame(['sent', 'finalize'], $revisions->pluck('reason')->all());
        $this->assertSame(['finalized', 'draft'], $revisions->pluck('status_before')->all());
        $this->assertSame('2026-10-02 09:15:00', $revisions[0]->finalized_at_before->toDateTimeString());
        $this->assertNull($revisions[0]->sent_at_before);
        $this->assertNull($revisions[1]->finalized_at_before);
    }

    public function test_a_report_is_not_finalized_while_a_timer_runs_inside_its_period(): void
    {
        $report = $this->report(ClientReport::STATUS_DRAFT);
        $project = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Site']);
        $timer = TimeEntry::create([
            'account_id' => $this->account->id, 'project_id' => $project->id, 'client_id' => $this->client->id,
            'source' => TimeEntry::SOURCE_MANUAL, 'start_time' => '2026-09-30 20:00:00', 'end_time' => null, 'billable' => true,
        ]);

        $this->transition($report, 'finalize')->assertSessionHas('error', "Stop the timer running in this report's period first.");
        $this->assertSame(ClientReport::STATUS_DRAFT, $report->fresh()->status);

        $timer->update(['end_time' => '2026-09-30 21:00:00', 'duration_minutes' => 60]);
        TimeEntry::create([
            'account_id' => $this->account->id, 'project_id' => $project->id, 'client_id' => $this->client->id,
            'source' => TimeEntry::SOURCE_MANUAL, 'start_time' => '2026-10-01 08:00:00', 'end_time' => null, 'billable' => true,
        ]);
        $this->transition($report, 'finalize')->assertSessionHas('success', 'Report finalized.');
    }

    public function test_the_running_timer_bound_is_the_local_midnight_after_the_reports_last_day(): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);
        $report = $this->report(ClientReport::STATUS_DRAFT);
        $project = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Site']);
        $timer = TimeEntry::create([
            'account_id' => $this->account->id, 'project_id' => $project->id, 'client_id' => $this->client->id,
            'source' => TimeEntry::SOURCE_MANUAL, 'start_time' => '2026-09-30 21:59:00', 'end_time' => null, 'billable' => true,
        ]);

        $this->transition($report, 'finalize')->assertSessionHas('error', "Stop the timer running in this report's period first.");

        $timer->update(['start_time' => '2026-09-30 22:00:00']);
        $this->transition($report, 'finalize')->assertSessionHas('success', 'Report finalized.');
    }

    public function test_marking_a_sent_report_as_sent_again_keeps_its_date_and_records_nothing(): void
    {
        $report = $this->report(ClientReport::STATUS_SENT, sentAt: '2026-10-03 14:40:00');

        $this->travelTo(Carbon::parse('2026-10-09 08:00:00', 'UTC'));
        $this->transition($report, 'mark-sent')->assertSessionHas('success', 'The report is already marked as sent.');

        $fresh = $report->fresh();
        $this->assertSame(ClientReport::STATUS_SENT, $fresh->status);
        $this->assertSame('2026-10-03 14:40:00', $fresh->sent_at->toDateTimeString());
        $this->assertCount(0, $fresh->revisions);
    }

    public function test_a_draft_cannot_be_marked_as_sent(): void
    {
        $report = $this->report(ClientReport::STATUS_DRAFT);

        $this->transition($report, 'mark-sent')->assertSessionHas('error', 'Finalize the report first.');

        $fresh = $report->fresh();
        $this->assertSame(ClientReport::STATUS_DRAFT, $fresh->status);
        $this->assertNull($fresh->sent_at);
        $this->assertCount(0, $fresh->revisions);
    }

    public function test_finalizing_a_sent_report_never_demotes_it(): void
    {
        $report = $this->report(ClientReport::STATUS_SENT, sentAt: '2026-10-03 14:40:00');

        $this->transition($report, 'finalize')->assertSessionHas('success', 'The report has already been sent, so it stays sent.');

        $fresh = $report->fresh();
        $this->assertSame(ClientReport::STATUS_SENT, $fresh->status);
        $this->assertSame('2026-10-03 14:40:00', $fresh->sent_at->toDateTimeString());
        $this->assertCount(0, $fresh->revisions);
    }

    public function test_a_finalize_from_a_page_loaded_before_the_report_was_sent_keeps_it_sent(): void
    {
        $report = $this->report(ClientReport::STATUS_DRAFT);
        $this->afterNextLoad($report, ['status' => ClientReport::STATUS_SENT, 'finalized_at' => '2026-10-02 09:15:00', 'sent_at' => '2026-10-03 14:40:00']);

        $this->transition($report, 'finalize')->assertSessionHas('success');

        $fresh = $report->fresh();
        $this->assertSame(ClientReport::STATUS_SENT, $fresh->status);
        $this->assertSame('2026-10-02 09:15:00', $fresh->finalized_at->toDateTimeString());
        $this->assertCount(0, $fresh->revisions);
    }

    public function test_a_mark_as_sent_after_the_report_was_reopened_meanwhile_is_refused(): void
    {
        $report = $this->report(ClientReport::STATUS_FINALIZED);
        $this->afterNextLoad($report, ['status' => ClientReport::STATUS_DRAFT, 'finalized_at' => null]);

        $this->transition($report, 'mark-sent')->assertSessionHas('error', 'Finalize the report first.');

        $this->assertNull($report->fresh()->sent_at);
        $this->assertCount(0, $report->fresh()->revisions);
    }

    public function test_reopening_a_sent_report_clears_both_dates_and_its_revision_keeps_them(): void
    {
        $report = $this->report(ClientReport::STATUS_SENT, sentAt: '2026-10-03 14:40:00');

        $this->transition($report, 'reopen')->assertSessionHas('success', 'Report reopened as draft.');

        $fresh = $report->fresh();
        $this->assertSame(ClientReport::STATUS_DRAFT, $fresh->status);
        $this->assertNull($fresh->finalized_at);
        $this->assertNull($fresh->sent_at);

        $revision = $fresh->revisions->sole();
        $this->assertSame('reopen', $revision->reason);
        $this->assertSame('sent', $revision->status_before);
        $this->assertSame('2026-10-02 09:15:00', $revision->finalized_at_before->toDateTimeString());
        $this->assertSame('2026-10-03 14:40:00', $revision->sent_at_before->toDateTimeString());
    }

    public function test_reopening_a_draft_records_nothing(): void
    {
        $report = $this->report(ClientReport::STATUS_DRAFT);

        $this->transition($report, 'reopen')->assertSessionHas('success', 'The report is already a draft.');

        $this->assertCount(0, $report->fresh()->revisions);
    }

    public function test_a_report_sent_again_after_a_reopen_gets_the_new_date(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 09:00:00', 'UTC'));
        $report = $this->report(ClientReport::STATUS_SENT, sentAt: '2026-10-03 14:40:00');
        $this->transition($report, 'reopen');
        $this->transition($report, 'finalize');

        $this->travelTo(Carbon::parse('2026-10-07 10:00:00', 'UTC'));
        $this->transition($report, 'mark-sent');

        $this->assertSame('2026-10-07 10:00:00', $report->fresh()->sent_at->toDateTimeString());
        $this->assertSame(['sent', 'finalize', 'reopen'], $report->fresh()->revisions->pluck('reason')->all());
    }

    public function test_another_accounts_report_is_not_found_for_every_transition(): void
    {
        $otherAccount = Account::create(['name' => 'Other']);
        $otherClient = $otherAccount->clients()->create(['name' => 'Other', 'currency' => 'PLN']);
        $foreign = $this->report(ClientReport::STATUS_FINALIZED, client: $otherClient);

        foreach (['finalize', 'mark-sent', 'reopen'] as $action) {
            $this->actingAs($this->user)->post("/clients/{$otherClient->id}/reports/{$foreign->id}/{$action}")->assertNotFound();
            $this->actingAs($this->user)->post("/clients/{$this->client->id}/reports/{$foreign->id}/{$action}")->assertNotFound();
        }

        $this->assertSame(ClientReport::STATUS_FINALIZED, $foreign->fresh()->status);
        $this->assertCount(0, $foreign->fresh()->revisions);
    }

    public function test_a_report_of_another_client_in_the_same_account_is_not_found(): void
    {
        $sibling = $this->account->clients()->create(['name' => 'Sibling', 'currency' => 'PLN']);
        $report = $this->report(ClientReport::STATUS_FINALIZED, client: $sibling);

        $this->actingAs($this->user)->post("/clients/{$this->client->id}/reports/{$report->id}/mark-sent")->assertNotFound();

        $this->assertSame(ClientReport::STATUS_FINALIZED, $report->fresh()->status);
    }

    public function test_a_transition_locks_the_client_row_before_the_report_row(): void
    {
        $report = $this->report(ClientReport::STATUS_FINALIZED);
        $tables = [];
        DB::listen(function ($query) use (&$tables): void {
            if (preg_match('/^select .* from ["`](clients|client_reports)["`]/i', $query->sql, $match) === 1) {
                $tables[] = $match[1];
            }
        });

        app(ReportStatusTransition::class)->markSent($report, $this->user);

        $this->assertSame(['clients', 'client_reports'], array_slice($tables, 0, 2));
    }

    public function test_an_archived_clients_report_still_moves_under_the_lock(): void
    {
        $report = $this->report(ClientReport::STATUS_FINALIZED);
        $this->client->delete();

        $this->transition($report, 'mark-sent')->assertSessionHas('success', 'Report marked as sent.');

        $this->assertSame(ClientReport::STATUS_SENT, $report->fresh()->status);
    }

    public function test_the_report_page_carries_the_local_sent_day_and_the_revision_dates(): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);
        $report = $this->report(ClientReport::STATUS_FINALIZED);
        $this->travelTo(Carbon::parse('2026-10-03 22:30:00', 'UTC'));
        $this->transition($report, 'mark-sent');

        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/reports/{$report->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.status', 'sent')
                ->where('report.sent_on', '2026-10-04')
                ->where('report.sent_at', '2026-10-03T22:30:00+00:00')
                ->where('revisions.0.reason', 'sent')
                ->where('revisions.0.finalized_at_before', '2026-10-02T09:15:00+00:00')
                ->where('revisions.0.sent_at_before', null));
    }

    public function test_the_ledger_reads_the_sent_day_after_marking_the_report_as_sent(): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);
        $report = $this->report(ClientReport::STATUS_FINALIZED);
        $this->travelTo(Carbon::parse('2026-10-03 22:30:00', 'UTC'));
        $this->transition($report, 'mark-sent');

        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/rozliczenia")
            ->assertInertia(fn (Assert $page) => $page
                ->where('billing_ledger.rows.0.status', 'sent')
                ->where('billing_ledger.rows.0.sent_on', '2026-10-04'));
    }

    public function test_the_agent_brief_names_when_the_latest_report_was_sent(): void
    {
        $this->report(ClientReport::STATUS_SENT, sentAt: '2026-10-03 14:40:00');

        $latest = app(ClientBrief::class)->for($this->client)['latest_report'];

        $this->assertSame('sent', $latest['status']);
        $this->assertSame('2026-10-03T14:40:00+00:00', $latest['sent_at']);
    }

    private function transition(ClientReport $report, string $action): TestResponse
    {
        return $this->actingAs($this->user)
            ->from("/clients/{$report->client_id}/reports/{$report->id}")
            ->post("/clients/{$report->client_id}/reports/{$report->id}/{$action}")
            ->assertRedirect("/clients/{$report->client_id}/reports/{$report->id}");
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function afterNextLoad(ClientReport $report, array $changes): void
    {
        $done = false;
        ClientReport::retrieved(function (ClientReport $loaded) use ($report, $changes, &$done): void {
            if (! $done && $loaded->id === $report->id) {
                $done = true;
                ClientReport::query()->whereKey($report->id)->update($changes);
            }
        });
    }

    private function report(string $status, ?string $sentAt = null, ?Client $client = null): ClientReport
    {
        $client ??= $this->client;

        return $client->reports()->create([
            'account_id' => $client->account_id,
            'period_type' => 'month',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'contracted_hours' => 12,
            'actual_hours' => 3,
            'currency' => 'PLN',
            'composer_key' => 'structured_list',
            'body_markdown' => "# September 2026\n\nseed body",
            'status' => $status,
            'generated_at' => '2026-10-01 08:00:00',
            'finalized_at' => $status !== ClientReport::STATUS_DRAFT ? '2026-10-02 09:15:00' : null,
            'sent_at' => $sentAt,
        ]);
    }
}
