<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\Reports\Composers\StructuredListComposer;
use App\Services\Reports\ReportComposerRegistry;
use App\Services\Reports\ReportDataAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

final class ReportComposerTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Client $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Acme']);
        $this->client = $this->account->clients()->create([
            'name' => 'Acme',
            'currency' => 'PLN',
        ]);
        $this->project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'name' => 'Acme Site',
        ]);
    }

    public function test_aggregator_collects_time_entries_and_completed_tasks_in_the_window(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Plugin updates',
            'list_name' => 'Done',
            'is_completed' => true,
            'is_reviewed' => true,
            'is_reportable' => true,
        ]);
        // Finished inside the window.
        $task->update(['finished_at' => '2026-03-20 12:00:00']);

        TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'description' => 'WP core + plugins',
            'start_time' => '2026-03-20 10:00:00',
            'end_time' => '2026-03-20 12:00:00',
            'duration_minutes' => 120,
            'billable' => true,
        ]);
        // Outside window — should not be aggregated.
        TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => null,
            'source' => TimeEntry::SOURCE_MANUAL,
            'description' => 'February work',
            'start_time' => '2026-02-15 10:00:00',
            'end_time' => '2026-02-15 11:00:00',
            'duration_minutes' => 60,
            'billable' => true,
        ]);

        $context = app(ReportDataAggregator::class)->aggregate(
            $this->client,
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-03-31'),
            'month',
        );

        $this->assertSame(2.0, $context->actualHours);
        $this->assertCount(1, $context->tasks);
        $this->assertSame('Plugin updates', $context->tasks->first()['name']);
        $this->assertSame(120, $context->tasks->first()['minutes']);
        $this->assertCount(1, $context->timeEntries);
    }

    public function test_aggregator_uses_active_retainer_at_period_start(): void
    {
        $this->client->retainers()->create([
            'account_id' => $this->account->id,
            'monthly_hours' => 12,
            'currency' => 'PLN',
            'effective_from' => '2026-03-01',
            'effective_to' => '2026-04-01',
        ]);
        $this->client->retainers()->create([
            'account_id' => $this->account->id,
            'monthly_hours' => 15,
            'currency' => 'PLN',
            'effective_from' => '2026-04-01',
        ]);

        $march = app(ReportDataAggregator::class)->aggregate(
            $this->client,
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-03-31'),
            'month',
        );
        $april = app(ReportDataAggregator::class)->aggregate(
            $this->client,
            Carbon::parse('2026-04-01'),
            Carbon::parse('2026-04-30'),
            'month',
        );

        $this->assertSame(12.0, $march->contractedHours());
        $this->assertSame(15.0, $april->contractedHours());
    }

    public function test_structured_list_composer_produces_the_fixed_report_shape(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Plugin updates',
            'is_completed' => true,
            'is_reviewed' => true,
            'is_reportable' => true,
        ]);
        $task->update(['finished_at' => '2026-03-20 12:00:00']);

        TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => '2026-03-20 10:00:00',
            'end_time' => '2026-03-20 12:00:00',
            'duration_minutes' => 120,
            'billable' => true,
        ]);

        $this->client->retainers()->create([
            'account_id' => $this->account->id,
            'monthly_hours' => 12,
            'currency' => 'PLN',
            'effective_from' => '2026-03-01',
        ]);

        $context = app(ReportDataAggregator::class)->aggregate(
            $this->client,
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-03-31'),
            'month',
        );

        $body = (new StructuredListComposer)->compose($context);

        $this->assertStringContainsString('# March 2026', $body);
        $this->assertStringContainsString('## Development work', $body);
        $this->assertStringContainsString('- Plugin updates', $body);

        // No grouping by project and no per-item hours: the shape is a flat
        // bullet list, one bullet per reportable task.
        $this->assertStringNotContainsString('## Acme Site', $body);
        $this->assertStringNotContainsString('(2h)', $body);

        // The balance appears exactly once, at the end.
        $this->assertStringNotContainsString('Hours: 2h of 12h contracted', $body);
        $this->assertStringContainsString('## Billing summary', $body);
        $this->assertStringContainsString('Pool for the month: +12h', $body);
        $this->assertStringContainsString('Used in the month: −2h', $body);
        $this->assertStringContainsString('Opening balance for next month: +10h', $body);
        $this->assertSame(1, mb_substr_count($body, 'Billing summary'));
    }

    public function test_structured_composer_never_quotes_an_overage_cost(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Extra dev work',
            'is_completed' => true,
            'is_reviewed' => true,
            'is_reportable' => true,
        ]);
        $task->update(['finished_at' => '2026-03-20 12:00:00']);

        TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => '2026-03-20 10:00:00',
            'end_time' => '2026-03-20 22:00:00',
            'duration_minutes' => 720,
            'billable' => true,
        ]);

        $this->client->retainers()->create([
            'account_id' => $this->account->id,
            'monthly_hours' => 10,
            'monthly_fee' => 700,
            'overage_hourly_rate' => 165,
            'currency' => 'PLN',
            'effective_from' => '2026-03-01',
        ]);

        $context = app(ReportDataAggregator::class)->aggregate(
            $this->client,
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-03-31'),
            'month',
        );

        $body = (new StructuredListComposer)->compose($context);

        // 12h booked against a 10h pool is a 2h overrun, and the retainer
        // carries a 165 PLN/h overage rate. It must still produce no price:
        // the hours bank absorbs overruns and they are never invoiced, so a
        // cost figure would promise a charge that never arrives.
        $this->assertStringNotContainsString('Overage', $body);
        $this->assertStringNotContainsString('165', $body);
        $this->assertStringNotContainsString('330', $body);
        $this->assertStringNotContainsString('PLN', $body);

        // The overrun is visible as a negative closing balance instead.
        $this->assertStringContainsString('Used in the month: −12h', $body);
        $this->assertStringContainsString('Opening balance for next month: −2h', $body);
    }

    public function test_aggregator_excludes_non_reportable_tasks_and_their_time_entries(): void
    {
        // Reportable: 1h, shows up
        $reportable = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Ad-hoc dev work',
            'is_completed' => true,
            'is_reviewed' => true,
            'is_reportable' => true,
        ]);
        $reportable->update(['finished_at' => '2026-03-20 12:00:00']);
        TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $reportable->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => '2026-03-20 10:00:00',
            'end_time' => '2026-03-20 11:00:00',
            'duration_minutes' => 60,
            'billable' => true,
        ]);

        // Non-reportable: 3h logged but should not surface
        $internal = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Recurring monitoring',
            'is_completed' => true,
            'is_reviewed' => true,
            'is_reportable' => false,
        ]);
        $internal->update(['finished_at' => '2026-03-20 12:00:00']);
        TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $internal->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => '2026-03-22 10:00:00',
            'end_time' => '2026-03-22 13:00:00',
            'duration_minutes' => 180,
            'billable' => true,
        ]);

        $context = app(ReportDataAggregator::class)->aggregate(
            $this->client,
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-03-31'),
            'month',
        );

        $this->assertSame(1.0, $context->actualHours);
        $this->assertCount(1, $context->tasks);
        $this->assertSame('Ad-hoc dev work', $context->tasks->first()['name']);
    }

    public function test_structured_composer_omits_activity_section_when_no_reportable_tasks(): void
    {
        $this->client->update([
            'report_baseline_markdown' => "### Monitoring\n- Skanowanie",
        ]);

        $context = app(ReportDataAggregator::class)->aggregate(
            $this->client,
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-03-31'),
            'month',
        );

        $body = (new StructuredListComposer)->compose($context);

        $this->assertStringNotContainsString('## Development work', $body);

        // The baseline is emitted verbatim, carrying its own h3 sections. No
        // wrapper heading, so hand-written and generated reports agree.
        $this->assertStringNotContainsString('## Within retainer', $body);
        $this->assertStringContainsString("### Monitoring\n- Skanowanie", $body);
    }

    public function test_structured_composer_states_the_balance_even_with_no_reportable_work(): void
    {
        $this->client->update([
            'report_baseline_markdown' => "### Monitoring\n- Skanowanie",
        ]);
        $this->client->retainers()->create([
            'account_id' => $this->account->id,
            'monthly_hours' => 15,
            'currency' => 'PLN',
            'effective_from' => '2026-03-01',
        ]);

        $context = app(ReportDataAggregator::class)->aggregate(
            $this->client,
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-03-31'),
            'month',
        );

        $body = (new StructuredListComposer)->compose($context);

        // The old shape suppressed a "0h of 15h" opener here, because a zero
        // above the work section read as "you got nothing". With the figure
        // moved into the closing summary that tension is gone: the baseline
        // states the deliverable, and the pool still rolls forward in full.
        $this->assertStringNotContainsString('Hours:', $body);
        $this->assertStringContainsString("### Monitoring\n- Skanowanie", $body);
        $this->assertStringContainsString('## Billing summary', $body);
        $this->assertStringContainsString('Used in the month: 0h', $body);
        $this->assertStringContainsString('Opening balance for next month: +15h', $body);
    }

    public function test_structured_composer_states_the_balance_when_there_is_no_baseline(): void
    {
        $this->client->retainers()->create([
            'account_id' => $this->account->id,
            'monthly_hours' => 15,
            'currency' => 'PLN',
            'effective_from' => '2026-03-01',
        ]);

        $context = app(ReportDataAggregator::class)->aggregate(
            $this->client,
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-03-31'),
            'month',
        );

        $body = (new StructuredListComposer)->compose($context);

        $this->assertStringContainsString('## Billing summary', $body);
        $this->assertStringContainsString('Opening balance for the month: 0h', $body);
        $this->assertStringContainsString('Pool for the month: +15h', $body);
        $this->assertStringContainsString('Opening balance for next month: +15h', $body);
    }

    public function test_structured_composer_reports_hours_lost_to_the_carry_over_cap(): void
    {
        // 18h rolled in on top of a 12h pool is 30h, but the agreement caps
        // the carry-over at 20h. The client should see that 10h went away.
        $this->client->retainers()->create([
            'account_id' => $this->account->id,
            'monthly_hours' => 12,
            'rollover_cap_hours' => 20,
            'currency' => 'PLN',
            'effective_from' => '2026-03-01',
        ]);

        $this->client->reports()->create([
            'account_id' => $this->account->id,
            'period_type' => 'month',
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            // February closed on +18h (6 carried in, 12h pool, nothing
            // booked), which is what March opens with.
            'opening_balance_hours' => 6,
            'contracted_hours' => 12,
            'rollover_cap_hours' => 20,
            'actual_hours' => 0,
            'status' => ClientReport::STATUS_FINALIZED,
            'composer_key' => 'manual',
            'body_markdown' => '# February',
        ]);

        $context = app(ReportDataAggregator::class)->aggregate(
            $this->client,
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-03-31'),
            'month',
        );

        $body = (new StructuredListComposer)->compose($context);

        $this->assertStringContainsString('Opening balance for the month: +18h', $body);
        $this->assertStringContainsString('Opening balance for next month: +20h', $body);
        $this->assertStringContainsString(
            'Hours above the agreed carry-over cap, not carried forward: 10h',
            $body,
        );
    }

    public function test_structured_composer_only_borrows_a_clean_description_opener(): void
    {
        $cases = [
            // [description, expected bullet]
            ['Lista artykułów gubiła paginację.', '- Fix. Lista artykułów gubiła paginację.'],
            ['Multi line.
Second line ignored.', '- Fix. Multi line.'],
            // Markdown and URLs are left for the human editor rather than
            // half-rendered into a bullet.
            ['See **the ticket** for detail', '- Fix'],
            ['- a nested bullet', '- Fix'],
            ['Details at https://example.test/x', '- Fix'],
            [str_repeat('very long sentence ', 20), '- Fix'],
            ['', '- Fix'],
            [null, '- Fix'],
        ];

        foreach ($cases as [$description, $expected]) {
            $task = Task::create([
                'project_id' => $this->project->id,
                'name' => 'Fix',
                'description' => $description,
                'is_completed' => true,
                'is_reviewed' => true,
                'is_reportable' => true,
            ]);
            $task->update(['finished_at' => '2026-03-20 12:00:00']);

            $context = app(ReportDataAggregator::class)->aggregate(
                $this->client,
                Carbon::parse('2026-03-01'),
                Carbon::parse('2026-03-31'),
                'month',
            );

            $body = (new StructuredListComposer)->compose($context);

            $this->assertStringContainsString(
                $expected."\n",
                $body,
                'description: '.var_export($description, true),
            );

            $task->forceDelete();
        }
    }

    public function test_structured_composer_appends_baseline_scope_verbatim_when_set(): void
    {
        $this->client->update([
            'report_baseline_markdown' => "### Monitoring\n- Skanowanie podatności\n- Kopie zapasowe",
        ]);

        $context = app(ReportDataAggregator::class)->aggregate(
            $this->client,
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-03-31'),
            'month',
        );

        $body = (new StructuredListComposer)->compose($context);

        $this->assertStringContainsString(
            "### Monitoring\n- Skanowanie podatności\n- Kopie zapasowe",
            $body,
            'the baseline must survive verbatim, not paraphrased or re-wrapped',
        );
        $this->assertStringNotContainsString('## Within retainer', $body);
    }

    public function test_available_hours_carry_the_previous_balance_and_respect_the_cap(): void
    {
        $this->client->retainers()->create([
            'account_id' => $this->account->id,
            'monthly_hours' => 10,
            'rollover_cap_hours' => 20,
            'is_active' => true,
            'effective_from' => '2026-01-01',
        ]);

        // June closed 4h to the good.
        $this->client->reports()->create([
            'account_id' => $this->account->id,
            'period_type' => 'month',
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'opening_balance_hours' => 1,
            'contracted_hours' => 10,
            'rollover_cap_hours' => 20,
            'actual_hours' => 7,
            'status' => 'finalized',
            'composer_key' => 'manual',
            'body_markdown' => '# June',
        ]);

        $context = app(ReportDataAggregator::class)->aggregate(
            $this->client, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), 'month',
        );

        $this->assertSame(4.0, $context->openingBalanceHours);
        $this->assertSame(14.0, $context->availableHours());

        // 12h against a 10h pool is NOT overage when 14h were available.
        $this->assertSame(0.0, $context->overageHours());
    }

    public function test_the_cap_bounds_the_total_and_forfeits_the_overspill(): void
    {
        $report = $this->client->reports()->create([
            'account_id' => $this->account->id,
            'period_type' => 'month',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'opening_balance_hours' => 15,   // 15 + 10 = 25, above the 20h ceiling
            'contracted_hours' => 10,
            'rollover_cap_hours' => 20,
            'actual_hours' => 8,
            'status' => 'draft',
            'composer_key' => 'manual',
            'body_markdown' => '# July',
        ]);

        $this->assertSame(20.0, $report->availableHours());
        $this->assertSame(5.0, $report->forfeitedHours());
        $this->assertSame(12.0, $report->closingBalanceHours());
    }

    public function test_without_a_cap_the_balance_simply_accumulates(): void
    {
        $report = $this->client->reports()->create([
            'account_id' => $this->account->id,
            'period_type' => 'month',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'opening_balance_hours' => 15,
            'contracted_hours' => 10,
            'rollover_cap_hours' => null,
            'actual_hours' => 8,
            'status' => 'draft',
            'composer_key' => 'manual',
            'body_markdown' => '# July',
        ]);

        $this->assertSame(25.0, $report->availableHours());
        $this->assertSame(0.0, $report->forfeitedHours());
        $this->assertSame(17.0, $report->closingBalanceHours());
    }

    public function test_composer_registry_resolves_by_key_and_returns_default(): void
    {
        $registry = app(ReportComposerRegistry::class);

        $default = $registry->default();
        $this->assertSame('ai_narrative', $default->key());
        $this->assertSame('structured_list', $registry->get('structured_list')->key());
        $this->assertSame('ai_narrative', $registry->get('ai_narrative')->key());

        $this->expectException(RuntimeException::class);
        $registry->get('nonexistent_composer');
    }

    public function test_get_or_default_falls_back_for_a_hand_written_report(): void
    {
        $registry = app(ReportComposerRegistry::class);

        // 'manual' is a label on hand-written reports, not a composer. Asking
        // for it must yield the default so Regenerate works instead of 500ing.
        $this->assertSame('ai_narrative', $registry->getOrDefault('manual')->key());
        $this->assertSame('ai_narrative', $registry->getOrDefault(null)->key());
        $this->assertSame('structured_list', $registry->getOrDefault('structured_list')->key());
    }
}
