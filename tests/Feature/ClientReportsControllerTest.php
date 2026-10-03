<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Reports\Composers\AiNarrativeComposer;
use App\Services\Reports\Composers\StructuredListComposer;
use App\Services\Reports\NarrativePromptResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class ClientReportsControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    private Client $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Acme']);
        $this->user = User::factory()->create([
            'account_id' => $this->account->id,
            'owner' => true,
        ]);
        $this->client = $this->account->clients()->create([
            'name' => 'Acme',
            'currency' => 'PLN',
        ]);
        $this->project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'name' => 'Acme Site',
        ]);

        // Use the deterministic composer in tests — no OpenAI calls.
        $this->app->bind(
            \App\Services\Reports\ReportComposerRegistry::class,
            function ($app): \App\Services\Reports\ReportComposerRegistry {
                $registry = new \App\Services\Reports\ReportComposerRegistry;
                $registry->register($app->make(StructuredListComposer::class), default: true);

                return $registry;
            },
        );
    }

    public function test_store_creates_a_draft_report_with_snapshotted_hours(): void
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
            'end_time' => '2026-03-20 13:00:00',
            'duration_minutes' => 180,
            'billable' => true,
        ]);

        $this->client->retainers()->create([
            'account_id' => $this->account->id,
            'monthly_hours' => 12,
            'currency' => 'PLN',
            'effective_from' => '2026-03-01',
        ]);

        $this->actingAs($this->user)
            ->post("/clients/{$this->client->id}/reports", [
                'period_type' => 'month',
                'period_start' => '2026-03-01',
                'period_end' => '2026-03-31',
            ])
            ->assertRedirect();

        $report = $this->client->reports()->first();
        $this->assertNotNull($report);
        $this->assertSame('draft', $report->status);
        $this->assertSame('structured_list', $report->composer_key);
        $this->assertSame(3.0, (float) $report->actual_hours);
        $this->assertSame(12.0, (float) $report->contracted_hours);
        $this->assertStringContainsString('# March 2026', $report->body_markdown);
        $this->assertStringContainsString('Plugin updates', $report->body_markdown);
    }

    public function test_a_weekly_report_is_refused_rather_than_credited_a_monthly_pool(): void
    {
        $this->client->retainers()->create([
            'account_id' => $this->account->id,
            'monthly_hours' => 12,
            'currency' => 'PLN',
            'effective_from' => '2026-03-01',
        ]);

        $this->actingAs($this->user)
            ->post("/clients/{$this->client->id}/reports", [
                'period_type' => 'week',
                'period_start' => '2026-03-02',
                'period_end' => '2026-03-08',
            ])
            ->assertSessionHasErrors('period_type');

        $this->assertSame(0, $this->client->reports()->count());
    }

    public function test_update_saves_the_edited_body_when_status_is_draft(): void
    {
        $report = $this->makeReport();

        $this->actingAs($this->user)
            ->put("/clients/{$this->client->id}/reports/{$report->id}", [
                'body_markdown' => "# My edit\n\nThis is the user's version.",
                'version' => $report->version(),
            ])
            ->assertRedirect();

        $this->assertSame("# My edit\n\nThis is the user's version.", $report->fresh()->body_markdown);
    }

    public function test_opening_balance_edit_rewrites_the_printed_summary_and_records_a_revision(): void
    {
        $report = $this->makeReport();
        $report->update(['body_markdown' => "# March 2026\n\n## Billing summary\n\nOpening balance for the month: 0h\n\nPool for the month: +12h\n\nUsed in the month: \u{2212}3h\n\nOpening balance for next month: +9h\n"]);

        $this->actingAs($this->user)
            ->patch("/clients/{$this->client->id}/reports/{$report->id}/opening-balance", [
                'opening_balance_hours' => -4,
            ])
            ->assertRedirect();

        $fresh = $report->fresh();
        $this->assertSame('-4.00', $fresh->opening_balance_hours);
        $this->assertStringContainsString("Opening balance for the month: \u{2212}4h", $fresh->body_markdown);
        $this->assertStringContainsString('Opening balance for next month: +5h', $fresh->body_markdown);
        $this->assertSame(1, mb_substr_count($fresh->body_markdown, 'Billing summary'));
        $this->assertSame($fresh->closingBalanceHours(), 5.0);

        // Sending the same balance again records nothing more.
        $this->actingAs($this->user)
            ->patch("/clients/{$this->client->id}/reports/{$report->id}/opening-balance", ['opening_balance_hours' => -4]);

        $revision = $fresh->fresh()->revisions->sole();
        $this->assertSame('opening_balance', $revision->reason);
        $this->assertNull($revision->opening_balance_hours_before);
        $this->assertStringContainsString('Opening balance for next month: +9h', $revision->body_markdown_before);
    }

    public function test_opening_balance_edit_rewrites_the_summary_in_the_unsaved_body_it_is_sent(): void
    {
        $report = $this->makeReport();

        $this->actingAs($this->user)
            ->patch("/clients/{$this->client->id}/reports/{$report->id}/opening-balance", [
                'opening_balance_hours' => 2,
                'body_markdown' => "# Marzec\n\n- Nowa sekcja\n\n## Podsumowanie rozliczeniowe\n\nBilans na start marca: 0h\n\n## Stopka\n",
                'version' => $report->version(),
            ])
            ->assertRedirect();

        $body = $report->fresh()->body_markdown;
        $this->assertStringContainsString('- Nowa sekcja', $body);
        $this->assertStringContainsString('Bilans na start miesiąca: +2h', $body);
        $this->assertStringContainsString('Bilans na start kolejnego miesiąca: +11h', $body);
        $this->assertStringNotContainsString('marca', $body);
        $this->assertStringEndsWith("Bilans na start kolejnego miesiąca: +11h\n\n## Stopka\n", $body);
    }

    public function test_a_body_save_on_a_stale_version_is_refused_and_keeps_the_newer_text(): void
    {
        $report = $this->makeReport();
        $stale = $report->version();
        $report->update(['body_markdown' => 'newer text']);

        $this->actingAs($this->user)
            ->put("/clients/{$this->client->id}/reports/{$report->id}", [
                'body_markdown' => 'older edit',
                'version' => $stale,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('newer text', $report->fresh()->body_markdown);
        $this->assertCount(0, $report->fresh()->revisions);
    }

    public function test_a_balance_edit_with_editor_text_on_a_stale_version_is_refused(): void
    {
        $report = $this->makeReport();
        $stale = $report->version();
        $report->update(['opening_balance_hours' => 2]);

        $this->actingAs($this->user)
            ->patch("/clients/{$this->client->id}/reports/{$report->id}/opening-balance", [
                'opening_balance_hours' => 5,
                'body_markdown' => 'editor text',
                'version' => $stale,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $fresh = $report->fresh();
        $this->assertSame('2.00', $fresh->opening_balance_hours);
        $this->assertSame("# March 2026\n\nseed body", $fresh->body_markdown);
    }

    public function test_editor_text_without_a_version_is_rejected(): void
    {
        $report = $this->makeReport();

        $this->actingAs($this->user)
            ->patch("/clients/{$this->client->id}/reports/{$report->id}/opening-balance", [
                'opening_balance_hours' => 5,
                'body_markdown' => 'editor text',
            ])
            ->assertSessionHasErrors('version');

        $this->assertNull($report->fresh()->opening_balance_hours);
    }

    public function test_each_balance_edit_records_the_body_the_one_before_it_wrote(): void
    {
        $report = $this->makeReport();
        $report->update(['body_markdown' => "# March 2026\n\n## Billing summary\n\nOpening balance for the month: 0h\n"]);

        foreach ([2, 5] as $hours) {
            $this->actingAs($this->user)
                ->patch("/clients/{$this->client->id}/reports/{$report->id}/opening-balance", [
                    'opening_balance_hours' => $hours,
                    'body_markdown' => $report->fresh()->body_markdown,
                    'version' => $report->fresh()->version(),
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        $revisions = $report->fresh()->revisions;
        $this->assertCount(2, $revisions);
        $this->assertStringContainsString('Opening balance for the month: +2h', $revisions[0]->body_markdown_before);
        $this->assertStringContainsString('Opening balance for the month: 0h', $revisions[1]->body_markdown_before);
    }

    public function test_opening_balance_edit_on_a_body_without_a_summary_says_the_text_was_left(): void
    {
        $report = $this->makeReport();

        $this->actingAs($this->user)
            ->patch("/clients/{$this->client->id}/reports/{$report->id}/opening-balance", [
                'opening_balance_hours' => 3,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', __('Opening balance saved. The report has no billing summary, so its text was left as is.'));

        $fresh = $report->fresh();
        $this->assertSame('3.00', $fresh->opening_balance_hours);
        $this->assertSame("# March 2026\n\nseed body", $fresh->body_markdown);
        $this->assertCount(1, $fresh->revisions);
    }

    public function test_a_body_with_two_billing_summaries_keeps_its_text_and_says_to_check_it(): void
    {
        $report = $this->makeReport();
        $body = "# March 2026\n\n## Billing summary\n\nOpening balance for the month: 0h\n\n## Billing summary\n\nOpening balance for the month: 0h\n";
        $report->update(['body_markdown' => $body]);

        $this->actingAs($this->user)
            ->patch("/clients/{$this->client->id}/reports/{$report->id}/opening-balance", ['opening_balance_hours' => 3])
            ->assertSessionHas('success', __('Opening balance saved. The report has more than one billing summary, so its text was left as is: check the balances in it by hand.'));

        $fresh = $report->fresh();
        $this->assertSame('3.00', $fresh->opening_balance_hours);
        $this->assertSame($body, $fresh->body_markdown);
    }

    public function test_the_change_history_carries_the_balance_a_revision_replaced(): void
    {
        $report = $this->makeReport();
        $report->update(['opening_balance_hours' => 2]);

        $this->actingAs($this->user)
            ->patch("/clients/{$this->client->id}/reports/{$report->id}/opening-balance", ['opening_balance_hours' => 5]);

        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/reports/{$report->id}")
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('revisions.0.reason', 'opening_balance')
                ->where('revisions.0.opening_balance_hours_before', 2)
                ->where('revisions.0.rollover_cap_hours_before', null)
                ->where('report.opening_balance_hours', 5));
    }

    public function test_a_balance_edit_without_the_field_changes_nothing(): void
    {
        $report = $this->makeReport();
        $report->update(['opening_balance_hours' => 4]);

        $this->actingAs($this->user)
            ->patch("/clients/{$this->client->id}/reports/{$report->id}/opening-balance", [])
            ->assertSessionHasErrors('opening_balance_hours');

        $this->assertSame('4.00', $report->fresh()->opening_balance_hours);
        $this->assertCount(0, $report->fresh()->revisions);
    }

    public function test_an_explicit_null_clears_the_balance(): void
    {
        $report = $this->makeReport();
        $report->update(['opening_balance_hours' => 4]);

        $this->actingAs($this->user)
            ->patch("/clients/{$this->client->id}/reports/{$report->id}/opening-balance", ['opening_balance_hours' => null])
            ->assertSessionHasNoErrors();

        $this->assertNull($report->fresh()->opening_balance_hours);
    }

    public function test_regenerate_keeps_the_saved_opening_balance_and_stores_the_cap_it_used(): void
    {
        $this->client->retainers()->create([
            'account_id' => $this->account->id,
            'monthly_hours' => 12,
            'rollover_cap_hours' => 40,
            'currency' => 'PLN',
            'effective_from' => '2026-01-01',
        ]);
        $report = $this->makeReport();
        $report->update(['opening_balance_hours' => 7, 'actual_hours' => 0]);

        $this->actingAs($this->user)
            ->post("/clients/{$this->client->id}/reports/{$report->id}/regenerate")
            ->assertRedirect();

        $fresh = $report->fresh();
        $this->assertSame(7.0, (float) $fresh->opening_balance_hours);
        $this->assertSame(40.0, (float) $fresh->rollover_cap_hours);
        $this->assertSame(19.0, $fresh->closingBalanceHours());
        $this->assertStringContainsString('Opening balance for the month: +7h', $fresh->body_markdown);
        $this->assertStringContainsString('Opening balance for next month: +19h', $fresh->body_markdown);

        $revision = $fresh->revisions->sole();
        $this->assertSame(7.0, (float) $revision->opening_balance_hours_before);
        $this->assertNull($revision->rollover_cap_hours_before);
    }

    public function test_regenerate_carries_the_opening_balance_in_again_only_when_asked(): void
    {
        $this->client->retainers()->create([
            'account_id' => $this->account->id,
            'monthly_hours' => 12,
            'currency' => 'PLN',
            'effective_from' => '2026-01-01',
        ]);
        $report = $this->makeReport();
        $report->update(['opening_balance_hours' => 7]);

        $this->actingAs($this->user)
            ->post("/clients/{$this->client->id}/reports/{$report->id}/regenerate", ['recalculate_opening_balance' => true])
            ->assertRedirect();

        // No earlier report, so the carried-in balance is 0h, in the row and in the body.
        $fresh = $report->fresh();
        $this->assertSame(0.0, (float) $fresh->opening_balance_hours);
        $this->assertStringContainsString('Opening balance for the month: 0h', $fresh->body_markdown);
        $this->assertSame(7.0, (float) $fresh->revisions->sole()->opening_balance_hours_before);
    }

    public function test_update_allows_edits_on_finalized_reports_and_logs_a_revision(): void
    {
        $report = $this->makeReport(status: ClientReport::STATUS_FINALIZED);

        $this->actingAs($this->user)
            ->put("/clients/{$this->client->id}/reports/{$report->id}", [
                'body_markdown' => 'post-finalize edit',
                'version' => $report->version(),
            ])
            ->assertRedirect();

        $fresh = $report->fresh();
        $this->assertSame('post-finalize edit', $fresh->body_markdown);
        $this->assertSame('finalized', $fresh->status);
        $this->assertCount(1, $fresh->revisions);
        $rev = $fresh->revisions->first();
        $this->assertSame('update', $rev->reason);
        $this->assertSame('finalized', $rev->status_before);
        $this->assertStringContainsString('seed body', $rev->body_markdown_before);
        $this->assertSame($this->user->id, $rev->user_id);
    }

    public function test_update_with_unchanged_body_does_not_create_a_revision(): void
    {
        $report = $this->makeReport();

        $this->actingAs($this->user)
            ->put("/clients/{$this->client->id}/reports/{$report->id}", [
                'body_markdown' => $report->body_markdown,
                'version' => $report->version(),
            ])
            ->assertRedirect();

        $this->assertCount(0, $report->fresh()->revisions);
    }

    public function test_reopen_flips_finalized_to_draft_and_logs_revision(): void
    {
        $report = $this->makeReport(status: ClientReport::STATUS_FINALIZED);

        $this->actingAs($this->user)
            ->post("/clients/{$this->client->id}/reports/{$report->id}/reopen")
            ->assertRedirect();

        $fresh = $report->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->finalized_at);
        $this->assertCount(1, $fresh->revisions);
        $this->assertSame('reopen', $fresh->revisions->first()->reason);
        $this->assertSame('finalized', $fresh->revisions->first()->status_before);
    }

    public function test_finalize_sets_status_and_logs_revision(): void
    {
        $report = $this->makeReport();

        $this->actingAs($this->user)
            ->post("/clients/{$this->client->id}/reports/{$report->id}/finalize")
            ->assertRedirect();

        $report->refresh();
        $this->assertSame('finalized', $report->status);
        $this->assertNotNull($report->finalized_at);
        $this->assertCount(1, $report->revisions);
        $this->assertSame('finalize', $report->revisions->first()->reason);
        $this->assertSame('draft', $report->revisions->first()->status_before);

        // Re-finalizing a finalized report is a no-op (no extra revision).
        $this->actingAs($this->user)
            ->post("/clients/{$this->client->id}/reports/{$report->id}/finalize")
            ->assertRedirect();
        $this->assertCount(1, $report->fresh()->revisions);
    }

    public function test_regenerate_refreshes_body_and_hours_snapshot(): void
    {
        $report = $this->makeReport();
        $report->update(['body_markdown' => 'user edits']);

        // Add a reportable task + a time entry on it after the report was
        // first generated. Regenerate should pick it up; non-reportable
        // tasks would stay out of the snapshot.
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Late reportable work',
            'is_completed' => false,
            'is_reviewed' => true,
            'is_reportable' => true,
        ]);
        TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'description' => 'late entry',
            'start_time' => $report->period_start->copy()->addDays(5)->setTime(10, 0)->toDateTimeString(),
            'end_time' => $report->period_start->copy()->addDays(5)->setTime(12, 0)->toDateTimeString(),
            'duration_minutes' => 120,
            'billable' => true,
        ]);

        $this->actingAs($this->user)
            ->post("/clients/{$this->client->id}/reports/{$report->id}/regenerate")
            ->assertRedirect();

        $report->refresh();
        $this->assertNotSame('user edits', $report->body_markdown);
        $this->assertSame(2.0, (float) $report->actual_hours);
    }

    public function test_regenerate_refuses_when_the_report_changed_while_composing(): void
    {
        $report = $this->makeReport();
        // A composer that stands in for an owner saving in another tab mid-compose.
        $this->app->bind(
            \App\Services\Reports\ReportComposerRegistry::class,
            function () use ($report): \App\Services\Reports\ReportComposerRegistry {
                $registry = new \App\Services\Reports\ReportComposerRegistry;
                $registry->register(new class($report) implements \App\Services\Reports\ReportComposerInterface
                {
                    public function __construct(private ClientReport $report) {}

                    public function key(): string
                    {
                        return 'structured_list';
                    }

                    public function label(): string
                    {
                        return 'slow';
                    }

                    public function compose(\App\Services\Reports\ReportContext $context): string
                    {
                        ClientReport::query()->whereKey($this->report->id)->update(['body_markdown' => 'saved meanwhile', 'opening_balance_hours' => 6]);

                        return 'regenerated';
                    }
                }, default: true);

                return $registry;
            },
        );

        $this->actingAs($this->user)
            ->post("/clients/{$this->client->id}/reports/{$report->id}/regenerate")
            ->assertRedirect()
            ->assertSessionHas('error');

        $fresh = $report->fresh();
        $this->assertSame('saved meanwhile', $fresh->body_markdown);
        $this->assertSame('6.00', $fresh->opening_balance_hours);
        $this->assertCount(0, $fresh->revisions);
    }

    public function test_finalize_and_reopen_snapshot_what_is_stored_when_they_run(): void
    {
        $report = $this->makeReport();
        // Another request saves new text right after this one loaded the report.
        $this->afterNextLoad($report, ['body_markdown' => 'saved meanwhile']);

        $this->actingAs($this->user)->post("/clients/{$this->client->id}/reports/{$report->id}/finalize")->assertRedirect();

        $this->assertSame('saved meanwhile', $report->fresh()->revisions->sole()->body_markdown_before);

        // A reopen raced by another reopen sees the draft and records nothing.
        $this->afterNextLoad($report, ['status' => ClientReport::STATUS_DRAFT, 'finalized_at' => null]);

        $this->actingAs($this->user)->post("/clients/{$this->client->id}/reports/{$report->id}/reopen")->assertRedirect();

        $this->assertCount(1, $report->fresh()->revisions);
    }

    public function test_destroy_removes_the_report(): void
    {
        $report = $this->makeReport();

        $this->actingAs($this->user)
            ->delete("/clients/{$this->client->id}/reports/{$report->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('client_reports', ['id' => $report->id]);
    }

    public function test_ai_narrative_composer_falls_back_to_structured_when_provider_throws(): void
    {
        $fallback = $this->app->make(StructuredListComposer::class);
        $throwing = new class implements \App\Services\AI\AIProviderInterface
        {
            public function chat(string $systemPrompt, array $messages, array $options = []): string
            {
                throw new RuntimeException('boom');
            }

            public function chatWithTools(string $systemPrompt, array $messages, array $tools, array $options = []): array
            {
                return ['content' => null, 'tool_calls' => []];
            }

            public function getProviderName(): string
            {
                return 'fake';
            }
        };

        $composer = new AiNarrativeComposer($throwing, $fallback, $this->promptResolver());

        $context = app(\App\Services\Reports\ReportDataAggregator::class)->aggregate(
            $this->client,
            \Illuminate\Support\Carbon::parse('2026-03-01'),
            \Illuminate\Support\Carbon::parse('2026-03-31'),
            'month',
        );

        $body = $composer->compose($context);

        // Fallback markdown shape always starts with the structured h1.
        $this->assertStringContainsString('# March 2026', $body);
    }

    public function test_ai_payload_sends_the_budget_so_a_pool_overrun_is_not_an_overage(): void
    {
        // 4h rolled in on a 10h pool, 12h booked. Over the pool, inside the
        // budget: the model must be handed available_hours and a zero overage,
        // or it quotes the client a cost they do not owe.
        $this->client->retainers()->create([
            'account_id' => $this->account->id,
            'monthly_hours' => 10,
            'overage_hourly_rate' => 130,
            'rollover_cap_hours' => 20,
            'is_active' => true,
            'effective_from' => '2026-01-01',
        ]);

        $this->client->reports()->create([
            'account_id' => $this->account->id,
            'period_type' => 'month',
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'opening_balance_hours' => 0,
            'contracted_hours' => 10,
            'rollover_cap_hours' => 20,
            'actual_hours' => 6,
            'status' => ClientReport::STATUS_FINALIZED,
            'composer_key' => 'manual',
            'body_markdown' => '# February',
        ]);

        $captured = null;
        $capturing = new class($captured) implements \App\Services\AI\AIProviderInterface
        {
            public function __construct(public ?string &$payload) {}

            public function chat(string $systemPrompt, array $messages, array $options = []): string
            {
                $this->payload = $messages[0]['content'];

                return '# March 2026';
            }

            public function chatWithTools(string $systemPrompt, array $messages, array $tools, array $options = []): array
            {
                return ['content' => null, 'tool_calls' => []];
            }

            public function getProviderName(): string
            {
                return 'capturing';
            }
        };

        $context = app(\App\Services\Reports\ReportDataAggregator::class)->aggregate(
            $this->client,
            \Illuminate\Support\Carbon::parse('2026-03-01'),
            \Illuminate\Support\Carbon::parse('2026-03-31'),
            'month',
        );

        (new AiNarrativeComposer(
            $capturing,
            $this->app->make(StructuredListComposer::class),
            $this->promptResolver(),
        ))->compose($context);

        $sent = json_decode((string) $capturing->payload, true);

        $this->assertSame(4.0, (float) $sent['opening_balance_hours']);
        $this->assertSame(10.0, (float) $sent['contracted_hours']);
        $this->assertSame(14.0, (float) $sent['available_hours']);
        $this->assertSame(20.0, (float) $sent['rollover_cap_hours']);
        // The whole point: over the pool, but nothing to bill.
        $this->assertSame(0.0, (float) $sent['overage_hours']);
    }

    /**
     * A fixed prompt, so these tests exercise the composer rather than
     * whatever wording the instance happens to carry.
     */
    private function promptResolver(): NarrativePromptResolver
    {
        return new class implements NarrativePromptResolver
        {
            public function resolve(int $accountId): string
            {
                return 'Write the report.';
            }

            public function source(int $accountId): ?string
            {
                return 'file';
            }
        };
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

    private function makeReport(string $status = ClientReport::STATUS_DRAFT): ClientReport
    {
        return $this->client->reports()->create([
            'account_id' => $this->account->id,
            'period_type' => 'month',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'contracted_hours' => 12,
            'actual_hours' => 3,
            'currency' => 'PLN',
            'composer_key' => 'structured_list',
            'body_markdown' => "# March 2026\n\nseed body",
            'status' => $status,
            'generated_at' => now(),
            'finalized_at' => $status === ClientReport::STATUS_FINALIZED ? now() : null,
        ]);
    }
}
