<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Models\Account;
use App\Models\ClientMonthClosure;
use App\Models\ClientMonthSettlementEvent;
use App\Models\ClientReport;
use App\Services\Clients\HoursBank;
use App\Services\Clients\MonthSettlement;
use App\Services\Clients\SettlementOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

final class MonthSettlementTest extends TestCase
{
    use RefreshDatabase;
    use SettlementFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlement();
        $this->retainer(30, '2026-01-01');
    }

    public function test_closing_from_entries_freezes_the_live_figures_and_writes_one_event(): void
    {
        $this->entry('2026-09-10 08:00:00', 600);
        $this->entry('2026-09-11 08:00:00', 120, reportable: false);

        $this->close('2026-09')->assertSessionHas('success', 'September 2026 closed. Balance +20.0 h carries into October 2026.');

        $closure = ClientMonthClosure::query()->sole();
        $this->assertSame('2026-09', $closure->period);
        $this->assertSame($this->user->id, $closure->closed_by_user_id);
        $this->assertSame('entries', $closure->snapshot['source']);
        $this->assertNull($closure->snapshot['report_id']);
        $this->assertEquals(30, $closure->snapshot['contracted']);
        $this->assertEquals(10, $closure->snapshot['used']);
        $this->assertEquals(20, $closure->snapshot['closing']);
        $this->assertEquals(12, $closure->snapshot['logged']);
        $this->assertSame(1, $closure->snapshot['schema_version']);

        $event = ClientMonthSettlementEvent::query()->sole();
        $this->assertSame(ClientMonthSettlementEvent::ACTION_CLOSE, $event->action);
        $this->assertSame($closure->id, $event->closure_id);
        $this->assertSame($this->user->id, $event->actor_user_id);
        $this->assertEquals($closure->snapshot, $event->snapshot);

        $this->assertSame('2026-10', app(HoursBank::class)->activeMonth($this->client));
        $this->assertSame(20.0, app(HoursBank::class)->month($this->client, '2026-10')->openingBalanceHours);
    }

    public function test_a_close_hands_the_toast_its_figures_and_speaks_polish_numbers(): void
    {
        $this->entry('2026-09-10 08:00:00', 2490);

        $this->close('2026-09')->assertSessionHas('settlement', [
            'action' => 'closed',
            'period' => '2026-09',
            'closing' => -11.5,
            'next' => '2026-10',
            'recalculate' => null,
        ]);

        $closure = ClientMonthClosure::query()->sole();
        $this->reopen('2026-09', $closure->id)->assertSessionHas('settlement', ['action' => 'reopened', 'period' => '2026-09', 'recalculate' => null]);

        app()->setLocale('pl');
        $result = $this->settlement()->close($this->client, '2026-09', $this->hash('2026-09'), $this->user);
        $this->assertSame('Wrzesień 2026 zamknięty. Bilans −11,5 h przechodzi na październik 2026.', $result->message);
    }

    public function test_a_draft_reports_hours_are_authoritative_and_live_entries_ride_along_as_information(): void
    {
        $report = $this->report('2026-09', opening: 0, contracted: 30, actual: 32);
        $this->entry('2026-09-10 08:00:00', 2190);

        $preview = $this->settlement()->preview($this->client, '2026-09');
        $this->assertSame('report', $preview['snapshot']['source']);
        $this->assertSame($report->id, $preview['snapshot']['report_id']);
        $this->assertSame(ClientReport::STATUS_DRAFT, $preview['snapshot']['report_status']);
        $this->assertSame(32.0, $preview['snapshot']['used']);
        $this->assertSame(36.5, $preview['snapshot']['entries']);
        $this->assertNull($preview['refusal']);

        $this->close('2026-09', $preview['hash'])->assertSessionHas('success');
        $this->assertEquals(-2, ClientMonthClosure::query()->sole()->snapshot['closing']);
    }

    public function test_the_sent_report_governs_when_a_month_has_two(): void
    {
        $this->report('2026-09', opening: 0, contracted: 30, actual: 5);
        $sent = $this->report('2026-09', opening: 0, contracted: 30, actual: 28, status: ClientReport::STATUS_SENT);

        $this->close('2026-09')->assertSessionHas('success');

        $snapshot = ClientMonthClosure::query()->sole()->snapshot;
        $this->assertSame($sent->id, $snapshot['report_id']);
        $this->assertEquals(28, $snapshot['used']);
    }

    public function test_a_second_close_of_a_closed_month_changes_nothing_even_after_the_active_month_moved(): void
    {
        $this->close('2026-09')->assertSessionHas('success');

        $this->close('2026-09', hash: str_repeat('0', 40))->assertSessionHas('success', 'September 2026 is already closed.');

        $this->assertSame(1, ClientMonthClosure::query()->count());
        $this->assertSame(1, ClientMonthSettlementEvent::query()->count());
    }

    public function test_the_running_month_and_later_ones_never_close(): void
    {
        $this->close('2026-09');

        $this->close('2026-10')->assertSessionHas('error', 'The month in progress cannot be closed.');
        $this->close('2026-12', hash: str_repeat('0', 40))->assertSessionHas('error', 'The month in progress cannot be closed.');
        $this->assertSame(1, ClientMonthClosure::query()->count());
    }

    public function test_only_the_active_month_closes(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'UTC'));

        $this->close('2026-10')->assertSessionHas('error', 'Close September 2026 first.');
        $this->close('2026-08')->assertSessionHas('error', 'August 2026 has no retainer to settle.');
        $this->assertSame(0, ClientMonthClosure::query()->count());
    }

    public function test_a_month_before_a_closed_one_never_closes(): void
    {
        $this->travelTo(Carbon::parse('2026-12-20 10:00:00', 'UTC'));
        $this->client->retainers()->update(['effective_from' => '2026-10-01']);
        $this->settle('2026-10');
        \App\Models\ClientRetainer::query()->update(['effective_from' => '2026-09-01']);

        $this->close('2026-09')->assertSessionHas('error', 'Reopen October 2026 first: a later month is already closed.');

        $this->assertSame(['2026-10'], ClientMonthClosure::query()->pluck('period')->all());
    }

    public function test_a_timer_still_running_in_the_month_blocks_the_close(): void
    {
        $this->entry('2026-09-30 21:00:00', 0, running: true);

        $this->close('2026-09')->assertSessionHas('error', 'Stop the timer running for September 2026 first.');
        $this->assertSame(0, ClientMonthClosure::query()->count());
    }

    public function test_figures_that_moved_since_the_preview_refuse_the_close(): void
    {
        $stale = $this->settlement()->preview($this->client, '2026-09')['hash'];
        $this->entry('2026-09-10 08:00:00', 60);

        $this->close('2026-09', $stale)->assertSessionHas('error', 'The figures changed, check them again.');

        $this->assertSame(0, ClientMonthClosure::query()->count());
        $this->assertSame(0, ClientMonthSettlementEvent::query()->count());
    }

    public function test_a_finalized_or_sent_later_report_blocks_close_and_reopen(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'UTC'));
        $october = $this->report('2026-10', opening: 0, contracted: 30, actual: 4, status: ClientReport::STATUS_FINALIZED);

        $this->close('2026-09')->assertSessionHas('error', 'The report for October 2026 is already finalized or sent; reopen it first.');

        $october->update(['status' => ClientReport::STATUS_DRAFT, 'finalized_at' => null]);
        $closure = $this->settle('2026-09');
        $october->update(['status' => ClientReport::STATUS_SENT, 'sent_at' => now()]);

        $this->reopen('2026-09', $closure->id)->assertSessionHas('error', 'The report for October 2026 is already finalized or sent; reopen it first.');
        $this->assertNotNull($closure->fresh());
    }

    public function test_a_later_report_sent_between_the_preview_and_the_close_refuses_the_close(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'UTC'));
        $october = $this->report('2026-10', opening: 0, contracted: 30, actual: 4, status: ClientReport::STATUS_FINALIZED);
        $october->update(['status' => ClientReport::STATUS_DRAFT, 'finalized_at' => null]);
        $hash = $this->hash('2026-09');

        app(\App\Services\Reports\ReportStatusTransition::class)->finalize($october->fresh(), $this->user);
        app(\App\Services\Reports\ReportStatusTransition::class)->markSent($october->fresh(), $this->user);

        $this->close('2026-09', $hash)->assertSessionHas('error', 'The report for October 2026 is already finalized or sent; reopen it first.');
        $this->assertSame(0, ClientMonthClosure::query()->count());
    }

    public function test_closing_and_reopening_flag_every_later_draft_for_recalculation(): void
    {
        $this->travelTo(Carbon::parse('2026-12-20 10:00:00', 'UTC'));
        $october = $this->report('2026-10', opening: 0, contracted: 30, actual: 4);
        $november = $this->report('2026-11', opening: 26, contracted: 30, actual: 4);

        $this->close('2026-09')->assertSessionHas('success', 'September 2026 closed. Balance +30.0 h carries into October 2026. Recalculate the report for October 2026.');
        $this->assertTrue($october->fresh()->needs_recalculation);
        $this->assertTrue($november->fresh()->needs_recalculation);
        $this->assertSame(26.0, (float) $november->fresh()->opening_balance_hours, 'no automatic write into a later report');

        ClientReport::query()->update(['needs_recalculation' => false]);
        $this->reopen('2026-09', ClientMonthClosure::query()->sole()->id)->assertSessionHas('success', 'September 2026 is open again. Recalculate the report for October 2026.');
        $this->assertTrue($october->fresh()->needs_recalculation);
    }

    public function test_a_flagged_report_blocks_closing_its_month_and_any_later_one(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'UTC'));
        $september = $this->report('2026-09', opening: 0, contracted: 30, actual: 4);
        $september->update(['needs_recalculation' => true]);

        $this->close('2026-09')->assertSessionHas('error', 'Recalculate the report for September 2026 first.');

        $this->assertSame(0, ClientMonthClosure::query()->count());
    }

    public function test_only_the_latest_closure_reopens(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'UTC'));
        $september = $this->settle('2026-09');
        $this->settle('2026-10');

        $this->reopen('2026-09', $september->id)->assertSessionHas('error', 'Reopen October 2026 first: only the latest closed month reopens.');
        $this->assertSame(2, ClientMonthClosure::query()->count());
    }

    public function test_a_late_reopen_retry_never_reopens_a_newer_close_of_the_same_month(): void
    {
        $first = $this->settle('2026-09');
        $this->reopen('2026-09', $first->id)->assertSessionHas('success', 'September 2026 is open again.');
        $second = $this->settle('2026-09');

        $this->reopen('2026-09', $first->id)->assertSessionHas('success', 'September 2026 is already open.');

        $this->assertNotNull($second->fresh());
        $this->assertSame(['close', 'reopen', 'close'], ClientMonthSettlementEvent::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_reopening_keeps_the_discarded_figures_in_the_audit_and_brings_the_month_back(): void
    {
        $this->entry('2026-09-10 08:00:00', 600);
        $closure = $this->settle('2026-09');

        $this->reopen('2026-09', $closure->id);

        $this->assertNull($closure->fresh());
        $reopen = ClientMonthSettlementEvent::query()->where('action', 'reopen')->sole();
        $this->assertSame($closure->id, $reopen->closure_id);
        $this->assertEquals(10, $reopen->snapshot['used']);
        $this->assertSame('2026-09', app(HoursBank::class)->activeMonth($this->client));
    }

    public function test_entries_edited_after_the_close_change_logged_time_not_the_frozen_figures(): void
    {
        $this->entry('2026-09-10 08:00:00', 600);
        $this->settle('2026-09');

        $this->entry('2026-09-12 08:00:00', 300);

        $september = app(HoursBank::class)->month($this->client, '2026-09');
        $this->assertSame(10.0, $september->usedHours);
        $this->assertSame(15.0, $september->loggedHours);
        $this->assertSame(20.0, app(HoursBank::class)->openingBalance($this->client, '2026-10'));
    }

    public function test_a_failed_write_rolls_the_whole_close_back(): void
    {
        ClientMonthSettlementEvent::creating(fn () => throw new RuntimeException('disk full'));

        try {
            $this->settlement()->close($this->client, '2026-09', $this->hash('2026-09'), $this->user);
            $this->fail('The close did not fail.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, ClientMonthClosure::query()->count());
    }

    public function test_an_archived_client_still_closes_its_month(): void
    {
        $this->client->delete();

        $this->close('2026-09')->assertSessionHas('success');

        $this->assertSame(1, ClientMonthClosure::query()->count());
    }

    public function test_the_month_counts_entries_by_warsaw_day_across_daylight_saving(): void
    {
        $this->travelTo(Carbon::parse('2027-04-10 10:00:00', 'UTC'));
        $this->account->update(['settings' => ['settlement_from' => '2027-03']]);
        // 23:30 on 31 March in Warsaw (summer time) and 00:30 on 1 April.
        $this->entry('2027-03-31 21:30:00', 60);
        $this->entry('2027-03-31 22:30:00', 120);
        // 00:30 on 1 March in Warsaw (winter time), the night before the month starts in UTC.
        $this->entry('2027-02-28 23:30:00', 30);

        $preview = $this->settlement()->preview($this->client, '2027-03');

        $this->assertSame(1.5, $preview['snapshot']['used']);
    }

    public function test_another_accounts_client_and_a_malformed_month_are_not_found(): void
    {
        $stranger = Account::create(['name' => 'Other'])->clients()->create(['name' => 'Other', 'currency' => 'PLN']);

        $this->actingAs($this->user)->post("/clients/{$stranger->id}/months/2026-09/close", ['preview_hash' => str_repeat('a', 40)])->assertNotFound();
        $this->actingAs($this->user)->post("/clients/{$this->client->id}/months/2026-13/close", ['preview_hash' => str_repeat('a', 40)])->assertNotFound();
        $this->actingAs($this->user)->delete("/clients/{$stranger->id}/months/2026-09/close", ['closure_id' => 1])->assertNotFound();
        $this->actingAs($this->user)->post("/clients/{$this->client->id}/months/2026-09/close", [])->assertSessionHasErrors('preview_hash');
    }

    public function test_a_closure_of_another_client_cannot_be_reopened_through_this_one(): void
    {
        $sibling = $this->account->clients()->create(['name' => 'Sibling', 'currency' => 'PLN']);
        $this->retainer(10, '2026-01-01', client: $sibling);
        $closure = $this->settlement()->close($sibling, '2026-09', $this->settlement()->preview($sibling, '2026-09')['hash'])->closure;

        $this->reopen('2026-09', $closure->id)->assertSessionHas('success', 'September 2026 is already open.');

        $this->assertNotNull($closure->fresh());
    }

    public function test_the_service_answers_with_an_outcome(): void
    {
        $result = $this->settlement()->close($this->client, '2026-09', $this->hash('2026-09'));
        $this->assertSame(SettlementOutcome::Applied, $result->outcome);
        $this->assertSame(SettlementOutcome::Unchanged, $this->settlement()->close($this->client, '2026-09', $this->hash('2026-09'))->outcome);
        $this->assertSame(SettlementOutcome::Refused, $this->settlement()->close($this->client, '2026-10', $this->hash('2026-10'))->outcome);
    }

    private function close(string $period, ?string $hash = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->from("/clients/{$this->client->id}/rozliczenia")
            ->post("/clients/{$this->client->id}/months/{$period}/close", ['preview_hash' => $hash ?? $this->hash($period)])
            ->assertRedirect("/clients/{$this->client->id}/rozliczenia");
    }

    private function reopen(string $period, int $closureId): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->from("/clients/{$this->client->id}/rozliczenia")
            ->delete("/clients/{$this->client->id}/months/{$period}/close", ['closure_id' => $closureId])
            ->assertRedirect("/clients/{$this->client->id}/rozliczenia");
    }

    private function settle(string $period): ClientMonthClosure
    {
        $result = $this->settlement()->close($this->client, $period, $this->hash($period), $this->user);
        $this->assertSame(SettlementOutcome::Applied, $result->outcome, $result->message);

        return $result->closure;
    }

    private function hash(string $period): string
    {
        return $this->settlement()->preview($this->client, $period)['hash'];
    }

    private function settlement(): MonthSettlement
    {
        return app(MonthSettlement::class);
    }
}
