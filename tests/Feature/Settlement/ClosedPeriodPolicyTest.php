<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Models\ClientReport;
use App\Services\Clients\MonthSettlement;
use App\Services\Reports\Composers\StructuredListComposer;
use App\Services\Reports\ReportComposerInterface;
use App\Services\Reports\ReportComposerRegistry;
use App\Services\Reports\ReportContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class ClosedPeriodPolicyTest extends TestCase
{
    use RefreshDatabase;
    use SettlementFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlement(nowUtc: '2026-11-20 10:00:00');
        $this->retainer(30, '2026-01-01', cap: 60);
        $this->app->bind(ReportComposerRegistry::class, function ($app): ReportComposerRegistry {
            $registry = new ReportComposerRegistry;
            $registry->register($app->make(StructuredListComposer::class), default: true);

            return $registry;
        });
    }

    public function test_a_report_generated_for_a_closed_month_carries_the_closures_figures(): void
    {
        $this->entry('2026-09-10 08:00:00', 600);
        $this->settle('2026-09');
        $this->entry('2026-09-12 08:00:00', 300);
        $this->client->retainers()->delete();

        $this->store('2026-09')->assertSessionHas('success');

        $report = ClientReport::query()->sole();
        $this->assertSame(30.0, (float) $report->contracted_hours);
        $this->assertSame(10.0, (float) $report->actual_hours);
        $this->assertSame(0.0, (float) $report->opening_balance_hours);
        $this->assertSame(60.0, (float) $report->rollover_cap_hours);
        $this->assertStringContainsString('Used in the month: −10h', $report->body_markdown);
    }

    public function test_reports_generate_on_a_closed_month_uses_the_closure_and_refuses_an_hours_override(): void
    {
        $this->entry('2026-09-10 08:00:00', 600);
        $this->settle('2026-09');

        $this->artisan('reports:generate', ['client' => $this->client->id, '--period' => '2026-09', '--hours' => 12, '--composer' => 'structured_list', '--locale' => 'en'])
            ->expectsOutputToContain('Reopen September 2026 to change the figures.')
            ->assertFailed();
        $this->assertSame(0, ClientReport::query()->count());

        $this->artisan('reports:generate', ['client' => $this->client->id, '--period' => '2026-09', '--composer' => 'structured_list', '--locale' => 'en'])->assertSuccessful();
        $this->assertSame(10.0, (float) ClientReport::query()->sole()->actual_hours);
    }

    public function test_a_new_report_for_an_open_month_flags_later_drafts_and_respects_later_sent_reports(): void
    {
        $this->travelTo(Carbon::parse('2026-12-20 10:00:00', 'UTC'));
        $november = $this->report('2026-11', opening: 0, contracted: 30, actual: 1);

        $this->store('2026-10')->assertSessionHas('success');
        $this->assertTrue($november->fresh()->needs_recalculation);

        $november->update(['status' => ClientReport::STATUS_SENT, 'sent_at' => now(), 'needs_recalculation' => false]);
        $this->store('2026-09')->assertSessionHas('error', 'The report for November 2026 is already finalized or sent; reopen it first.');
        $this->assertSame(2, ClientReport::query()->count());
    }

    public function test_no_report_after_a_flagged_one_is_generated_or_recalculated(): void
    {
        $this->travelTo(Carbon::parse('2026-12-20 10:00:00', 'UTC'));
        $this->report('2026-09', opening: 0, contracted: 30, actual: 1)->update(['needs_recalculation' => true]);
        $october = $this->report('2026-10', opening: 29, contracted: 30, actual: 1);
        $october->update(['needs_recalculation' => true]);

        $this->store('2026-11')->assertSessionHas('error', 'Recalculate the report for September 2026 first.');
        $this->regenerate($october)->assertSessionHas('error', 'Recalculate the report for September 2026 first.');
        $this->artisan('reports:generate', ['client' => $this->client->id, '--period' => '2026-11', '--composer' => 'structured_list', '--locale' => 'en'])
            ->expectsOutputToContain('Recalculate the report for September 2026 first.')
            ->assertFailed();
        $this->assertSame(2, ClientReport::query()->count());
    }

    public function test_figures_that_move_while_composing_refuse_the_new_report(): void
    {
        $this->composeWith(fn () => $this->entry('2026-10-05 08:00:00', 60));

        $this->store('2026-10')->assertSessionHas('error', 'The figures changed, try again.');

        $this->assertSame(0, ClientReport::query()->count());
    }

    public function test_a_close_landing_while_a_report_composes_refuses_the_report(): void
    {
        $this->entry('2026-09-10 08:00:00', 600);
        $this->composeWith(function (): void {
            $settlement = app(MonthSettlement::class);
            $settlement->close($this->client, '2026-09', $settlement->preview($this->client, '2026-09')['hash']);
        });

        $this->store('2026-09')->assertSessionHas('error', 'The figures changed, try again.');

        $this->assertSame(0, ClientReport::query()->count());
    }

    public function test_a_close_landing_while_a_report_regenerates_refuses_the_regenerate(): void
    {
        $report = $this->report('2026-09', opening: 0, contracted: 30, actual: 10);
        $this->composeWith(function (): void {
            $settlement = app(MonthSettlement::class);
            $settlement->close($this->client, '2026-09', $settlement->preview($this->client, '2026-09')['hash']);
        });

        $this->regenerate($report)->assertSessionHas('error', 'Reopen September 2026 to change the figures.');

        $this->assertSame("# 2026-09\n\nWork done.", $report->fresh()->body_markdown);
        $this->assertCount(0, $report->fresh()->revisions);
    }

    public function test_a_report_just_created_in_memory_regenerates_through_the_service(): void
    {
        $report = $this->report('2026-10', opening: 7, contracted: 30, actual: 10);

        $result = app(\App\Services\Reports\ReportWriter::class)->regenerate($report, 'en', 'structured_list');

        $this->assertSame('Report regenerated.', $result->message);
    }

    public function test_a_report_flagged_while_it_regenerates_saves_nothing(): void
    {
        $october = $this->report('2026-10', opening: 7, contracted: 30, actual: 10);
        $this->composeWith(function () use ($october): void {
            ClientReport::query()->whereKey($october->id)->update(['needs_recalculation' => true]);
        });

        $this->regenerate($october)->assertSessionHas('error', 'The figures changed, try again.');

        $fresh = $october->fresh();
        $this->assertSame("# 2026-10\n\nWork done.", $fresh->body_markdown);
        $this->assertSame(7.0, (float) $fresh->opening_balance_hours);
        $this->assertTrue($fresh->needs_recalculation);
        $this->assertCount(0, $fresh->revisions);
    }

    public function test_a_closed_months_figures_refuse_regenerate_opening_edits_and_delete(): void
    {
        $report = $this->report('2026-09', opening: 0, contracted: 30, actual: 10);
        $this->settle('2026-09');
        $refusal = 'Reopen September 2026 to change the figures.';

        $this->regenerate($report)->assertSessionHas('error', $refusal);
        $this->opening($report, 5)->assertSessionHas('error', $refusal);
        $this->actingAs($this->user)->from('/x')->delete($this->url($report))->assertRedirect('/x')->assertSessionHas('error', $refusal);

        $fresh = $report->fresh();
        $this->assertSame(0.0, (float) $fresh->opening_balance_hours);
        $this->assertCount(0, $fresh->revisions);
    }

    public function test_a_later_closure_freezes_an_earlier_months_report_too(): void
    {
        $this->account->update(['settings' => ['settlement_from' => '2026-10']]);
        $august = $this->report('2026-08', opening: 0, contracted: 30, actual: 10, status: ClientReport::STATUS_SENT);
        $this->settle('2026-10');

        $this->opening($august, 5)->assertSessionHas('error', 'Reopen October 2026 to change the figures.');
        $this->regenerate($august)->assertSessionHas('error', 'Reopen October 2026 to change the figures.');
    }

    public function test_a_later_finalized_report_freezes_an_open_months_figures(): void
    {
        $september = $this->report('2026-09', opening: 0, contracted: 30, actual: 10);
        $this->report('2026-10', opening: 20, contracted: 30, actual: 10, status: ClientReport::STATUS_FINALIZED);
        $refusal = 'The report for October 2026 is already finalized or sent; reopen it first.';

        $this->regenerate($september)->assertSessionHas('error', $refusal);
        $this->opening($september, 3)->assertSessionHas('error', $refusal);
        $this->actingAs($this->user)->from('/x')->delete($this->url($september))->assertSessionHas('error', $refusal);
        $this->assertNotNull($september->fresh());
    }

    public function test_changing_an_open_months_figures_flags_the_drafts_after_it(): void
    {
        $september = $this->report('2026-09', opening: 0, contracted: 30, actual: 10);
        $october = $this->report('2026-10', opening: 20, contracted: 30, actual: 10);

        $this->opening($september, 3)->assertSessionHas('success');
        $this->assertTrue($october->fresh()->needs_recalculation);

        $october->update(['needs_recalculation' => false]);
        $this->regenerate($september)->assertSessionHas('success');
        $this->assertTrue($october->fresh()->needs_recalculation);

        $october->update(['needs_recalculation' => false]);
        $this->actingAs($this->user)->delete($this->url($september))->assertSessionHas('success');
        $this->assertTrue($october->fresh()->needs_recalculation);
    }

    public function test_regenerating_a_flagged_report_recalculates_its_opening_and_clears_the_flag(): void
    {
        $this->entry('2026-09-10 08:00:00', 600);
        $october = $this->report('2026-10', opening: 7, contracted: 30, actual: 10);
        $this->settle('2026-09');
        $this->assertTrue($october->fresh()->needs_recalculation);

        $this->regenerate($october)->assertSessionHas('success', 'Report regenerated.');

        $fresh = $october->fresh();
        $this->assertFalse($fresh->needs_recalculation);
        $this->assertSame(20.0, (float) $fresh->opening_balance_hours, 'carried in from the September closure');
    }

    public function test_a_flagged_report_is_not_finalized_or_sent_until_recalculated_and_nor_is_anything_after_it(): void
    {
        $october = $this->report('2026-10', opening: 7, contracted: 30, actual: 10);
        $october->update(['needs_recalculation' => true]);
        $later = $this->report('2026-11', opening: 27, contracted: 30, actual: 10, status: ClientReport::STATUS_FINALIZED);

        $this->transition($october, 'finalize')->assertSessionHas('error', 'Regenerate this report with the opening balance recalculated first: an earlier month changed.');
        $this->transition($later, 'mark-sent')->assertSessionHas('error', 'Recalculate the report for October 2026 first.');
        $this->assertSame(ClientReport::STATUS_DRAFT, $october->fresh()->status);
        $this->assertNull($later->fresh()->sent_at);

        $this->transition($later, 'reopen')->assertSessionHas('success');
    }

    public function test_a_closed_months_report_still_finalizes_and_goes_out(): void
    {
        $report = $this->report('2026-09', opening: 0, contracted: 30, actual: 10);
        $this->settle('2026-09');

        $this->transition($report, 'finalize')->assertSessionHas('success');
        $this->transition($report, 'mark-sent')->assertSessionHas('success');
        $this->assertSame(ClientReport::STATUS_SENT, $report->fresh()->status);
    }

    public function test_a_status_move_that_hands_a_closed_month_to_its_other_report_is_refused(): void
    {
        $this->report('2026-09', opening: 0, contracted: 30, actual: 10, status: ClientReport::STATUS_FINALIZED);
        $draft = $this->report('2026-09', opening: 0, contracted: 30, actual: 2);
        $this->settle('2026-09');

        $this->transition($draft, 'finalize')->assertSessionHas('error', 'Reopen September 2026 to change the figures.');
    }

    public function test_a_sent_legacy_partial_ending_the_same_day_does_not_mask_a_whole_month_governing_change(): void
    {
        $whole = $this->report('2026-09', opening: 0, contracted: 30, actual: 10, status: ClientReport::STATUS_FINALIZED);
        $this->report('2026-09', opening: 0, contracted: 30, actual: 2);
        $this->report('2026-09', opening: 0, contracted: 30, actual: 1, status: ClientReport::STATUS_SENT, start: '2026-09-15');
        $this->report('2026-10', opening: 20, contracted: 30, actual: 10, status: ClientReport::STATUS_SENT);

        $this->transition($whole, 'reopen')->assertSessionHas('error', 'The report for October 2026 is already finalized or sent; reopen it first.');

        $this->assertSame(ClientReport::STATUS_FINALIZED, $whole->fresh()->status);
    }

    public function test_the_brief_names_the_whole_month_report_the_bank_reads_not_a_partial_ending_the_same_day(): void
    {
        $whole = $this->report('2026-10', opening: 0, contracted: 30, actual: 10);
        $this->report('2026-10', opening: 0, contracted: 30, actual: 1, status: ClientReport::STATUS_SENT, start: '2026-10-15');

        $this->assertSame($whole->id, app(\App\Services\Agent\ClientBrief::class)->for($this->client)['latest_report']['id']);
    }

    public function test_reopening_a_report_that_hands_its_month_to_another_flags_the_drafts_after_it(): void
    {
        $finalized = $this->report('2026-09', opening: 0, contracted: 30, actual: 10, status: ClientReport::STATUS_FINALIZED);
        $this->report('2026-09', opening: 0, contracted: 30, actual: 2);
        $october = $this->report('2026-10', opening: 20, contracted: 30, actual: 10);

        $this->transition($finalized, 'reopen')->assertSessionHas('success');

        $this->assertTrue($october->fresh()->needs_recalculation);
    }

    public function test_finalizing_a_report_that_takes_its_month_over_flags_the_drafts_after_it(): void
    {
        $older = $this->report('2026-09', opening: 0, contracted: 30, actual: 10);
        $this->report('2026-09', opening: 0, contracted: 30, actual: 2);
        $october = $this->report('2026-10', opening: 28, contracted: 30, actual: 10);

        $this->transition($older, 'finalize')->assertSessionHas('success');

        $this->assertTrue($october->fresh()->needs_recalculation);
    }

    public function test_a_status_move_that_keeps_the_same_report_in_charge_flags_nothing(): void
    {
        $september = $this->report('2026-09', opening: 0, contracted: 30, actual: 10);
        $october = $this->report('2026-10', opening: 20, contracted: 30, actual: 10);

        $this->transition($september, 'finalize')->assertSessionHas('success');
        $this->transition($september, 'mark-sent')->assertSessionHas('success');

        $this->assertFalse($october->fresh()->needs_recalculation);
    }

    public function test_a_legacy_partial_is_frozen_by_a_sent_partial_later_in_the_same_month(): void
    {
        $this->account->update(['settings' => ['settlement_from' => '2026-10']]);
        $first = $this->report('2026-09', opening: 0, contracted: 30, actual: 5, end: '2026-09-15', type: ClientReport::PERIOD_WEEK);
        $this->report('2026-09', opening: 25, contracted: 30, actual: 5, status: ClientReport::STATUS_SENT, start: '2026-09-16', type: ClientReport::PERIOD_WEEK);

        $this->opening($first, 4)->assertSessionHas('error', 'The report for September 2026 is already finalized or sent; reopen it first.');
        $this->regenerate($first)->assertSessionHas('error', 'The report for September 2026 is already finalized or sent; reopen it first.');

        $this->assertSame(0.0, (float) $first->fresh()->opening_balance_hours);
    }

    public function test_a_legacy_partials_change_flags_the_draft_partial_after_it_in_the_same_month(): void
    {
        $this->account->update(['settings' => ['settlement_from' => '2026-10']]);
        $first = $this->report('2026-09', opening: 0, contracted: 30, actual: 5, end: '2026-09-15', type: ClientReport::PERIOD_WEEK);
        $second = $this->report('2026-09', opening: 25, contracted: 30, actual: 5, start: '2026-09-16', type: ClientReport::PERIOD_WEEK);

        $this->opening($first, 4)->assertSessionHas('success');
        $this->assertTrue($second->fresh()->needs_recalculation);

        $this->regenerate($second)->assertSessionHas('success');
        $this->assertFalse($second->fresh()->needs_recalculation);
        $this->transition($first, 'finalize')->assertSessionHas('success');
    }

    public function test_a_flagged_partial_blocks_the_partial_after_it_in_the_same_month(): void
    {
        $this->account->update(['settings' => ['settlement_from' => '2026-10']]);
        $this->report('2026-09', opening: 0, contracted: 30, actual: 5, end: '2026-09-15', type: ClientReport::PERIOD_WEEK)->update(['needs_recalculation' => true]);
        $second = $this->report('2026-09', opening: 25, contracted: 30, actual: 5, status: ClientReport::STATUS_FINALIZED, start: '2026-09-16', type: ClientReport::PERIOD_WEEK);

        $this->transition($second, 'mark-sent')->assertSessionHas('error', 'Recalculate the report for September 2026 first.');
    }

    public function test_a_closed_months_body_keeps_one_billing_summary_printed_from_the_closure(): void
    {
        $report = $this->report('2026-09', opening: 0, contracted: 30, actual: 10);
        $report->update(['body_markdown' => "# Wrzesień\n\nPrace.\n\n## Billing summary\n\nOpening balance for the month: +0h"]);
        $this->settle('2026-09');

        $this->update($report->fresh(), "# Wrzesień\n\nPrace poprawione.\n\n## Billing summary\n\nOpening balance for the month: +99h")->assertSessionHas('success');

        $body = $report->fresh()->body_markdown;
        $this->assertStringContainsString('Prace poprawione.', $body);
        $this->assertStringContainsString('Opening balance for next month: +20h', $body);
        $this->assertStringNotContainsString('+99h', $body);

        $this->update($report->fresh(), "# Wrzesień\n\nBez podsumowania.")->assertSessionHasErrors('body_markdown');
        $this->update($report->fresh(), $body."\n## Billing summary\n\nagain")->assertSessionHasErrors('body_markdown');
        $this->assertSame($body, $report->fresh()->body_markdown);
    }

    public function test_an_open_months_body_is_saved_as_written(): void
    {
        $report = $this->report('2026-10', opening: 0, contracted: 30, actual: 10);

        $this->update($report, "# Październik\n\nBez podsumowania.")->assertSessionHas('success');

        $this->assertSame("# Październik\n\nBez podsumowania.", $report->fresh()->body_markdown);
    }

    private function settle(string $period): void
    {
        $settlement = app(MonthSettlement::class);
        $result = $settlement->close($this->client, $period, $settlement->preview($this->client, $period)['hash'], $this->user);
        $this->assertFalse($result->isRefused(), $result->message);
    }

    private function url(ClientReport $report): string
    {
        return "/clients/{$this->client->id}/reports/{$report->id}";
    }

    private function store(string $period): TestResponse
    {
        $first = Carbon::parse($period.'-01');

        return $this->actingAs($this->user)->from("/clients/{$this->client->id}/rozliczenia")->post("/clients/{$this->client->id}/reports", [
            'period_type' => 'month',
            'period_start' => $first->toDateString(),
            'period_end' => $first->copy()->endOfMonth()->toDateString(),
            'composer_key' => 'structured_list',
        ]);
    }

    private function regenerate(ClientReport $report): TestResponse
    {
        return $this->actingAs($this->user)->from($this->url($report))->post($this->url($report).'/regenerate')->assertRedirect($this->url($report));
    }

    private function opening(ClientReport $report, float $hours): TestResponse
    {
        return $this->actingAs($this->user)->from($this->url($report))->patch($this->url($report).'/opening-balance', ['opening_balance_hours' => $hours])->assertRedirect($this->url($report));
    }

    private function update(ClientReport $report, string $body): TestResponse
    {
        return $this->actingAs($this->user)->from($this->url($report))->put($this->url($report), ['body_markdown' => $body, 'version' => $report->version()]);
    }

    private function transition(ClientReport $report, string $action): TestResponse
    {
        return $this->actingAs($this->user)->from($this->url($report))->post($this->url($report).'/'.$action)->assertRedirect($this->url($report));
    }

    private function composeWith(Closure $meanwhile): void
    {
        $this->app->bind(ReportComposerRegistry::class, function () use ($meanwhile): ReportComposerRegistry {
            $registry = new ReportComposerRegistry;
            $registry->register(new class($meanwhile) implements ReportComposerInterface
            {
                public function __construct(private Closure $meanwhile) {}

                public function key(): string
                {
                    return 'structured_list';
                }

                public function label(): string
                {
                    return 'slow';
                }

                public function compose(ReportContext $context): string
                {
                    ($this->meanwhile)();

                    return 'composed';
                }
            }, default: true);

            return $registry;
        });
    }
}
