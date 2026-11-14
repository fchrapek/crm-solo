<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendSummaryEmail;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientSummaryEmail;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\MonthCloseStep;
use App\Models\User;
use App\Services\Agent\AgentRefusal;
use App\Services\Messaging\MessageSettings;
use App\Services\Messaging\SummaryEmails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;
use Throwable;

/**
 * A summary email is drafted from the owner's template, sent once by the
 * owner's click, and counts as sent only when it really reached the client:
 * a test send never locks it or ticks the month close.
 */
final class SummaryEmailsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Client $client;

    private ArrayTransport $capture;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-11-03 10:00:00');
        $account = Account::factory()->create();
        $this->owner = User::factory()->create(['account_id' => $account->id, 'owner' => true, 'email' => 'owner@agency.test']);
        $this->client = Client::factory()->create([
            'account_id' => $account->id, 'name' => 'Brzozowy Sad', 'email' => 'Biuro@Brzozowy-Sad.test',
            'month_close_type' => 'gig', 'include_in_month_close' => true, 'report_mode' => 'summary_email',
        ]);
        Contact::factory()->create(['account_id' => $account->id, 'client_id' => $this->client->id, 'first_name' => 'Anna', 'last_name' => 'Nowak', 'emails' => ['anna@brzozowy-sad.test', 'biuro@brzozowy-sad.test']]);
        app(MessageSettings::class)->template($account->id, 'monthly', 'Miesięczne', "Dzień dobry {client_name},\n\nkrótka notka za {period}.", MessageTemplate::EMAIL, 'Podsumowanie za {period}');

        $this->capture = new ArrayTransport;
        Mail::extend('capture', fn (): ArrayTransport => $this->capture);
        config(['mail.mailers.capture' => ['transport' => 'capture']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_draft_needs_an_email_template(): void
    {
        MessageTemplate::query()->delete();

        $this->actingAs($this->owner)->withUnencryptedCookie('locale', 'pl')->post($this->url(), [])->assertSessionHasErrors(['template' => 'Najpierw dodaj szablon e-maila.']);
        $this->assertSame(0, ClientSummaryEmail::query()->count());
    }

    public function test_a_draft_is_made_from_the_template_and_offers_the_client_and_contact_addresses(): void
    {
        $this->actingAs($this->owner)->post($this->url(), [])->assertSessionHasNoErrors();

        $shown = $this->actingAs($this->owner)->getJson($this->url())->assertOk()->json();
        $this->assertSame('draft', $shown['email']['status']);
        $this->assertSame('Podsumowanie za październik 2026', $shown['email']['subject']);
        $this->assertStringContainsString('Dzień dobry Brzozowy Sad', $shown['email']['body']);
        $this->assertSame([], $shown['email']['recipients']);
        $this->assertTrue($shown['email']['bcc_owner']);
        $this->assertSame(['anna@brzozowy-sad.test', 'biuro@brzozowy-sad.test'], array_column($shown['candidates'], 'email'));
        $this->assertSame('test', $shown['delivery']);
    }

    public function test_the_month_close_card_shows_the_state_of_the_month_email_to_the_owner(): void
    {
        $state = fn (User $user) => $this->actingAs($user)->get("/month-close/{$this->client->id}?period=2026-10")->assertOk()->viewData('page')['props']['summary_email'];

        $this->assertNull($state($this->owner)['status']);
        $this->draft(['anna@brzozowy-sad.test']);
        $this->assertSame(['status' => 'draft', 'drafted_on' => '2026-11-03', 'drafted_via' => 'web', 'sent_on' => null, 'changed_on' => '2026-11-03', 'recipients' => 1], $state($this->owner));
        $this->actingAs(User::factory()->create(['account_id' => $this->owner->account_id, 'owner' => false]))->get("/month-close/{$this->client->id}?period=2026-10")->assertNotFound();
    }

    public function test_the_rozliczenia_tab_gives_the_owner_each_month_email_of_a_retainer_client(): void
    {
        $this->client->retainers()->create(['account_id' => $this->client->account_id, 'monthly_hours' => 5, 'monthly_fee' => 500, 'currency' => 'PLN', 'effective_from' => '2025-01-01']);
        $this->draft(['anna@brzozowy-sad.test']);
        $emails = fn (User $user) => $this->actingAs($user)->get("/clients/{$this->client->id}/rozliczenia")->assertOk()->viewData('page')['props']['billing_emails'];

        $this->assertSame('draft', $emails($this->owner)['2026-10']['status']);
        $this->actingAs(User::factory()->create(['account_id' => $this->owner->account_id, 'owner' => false]))->get("/clients/{$this->client->id}/rozliczenia")->assertNotFound();
        $this->client->update(['report_mode' => 'report']);
        $this->assertNull($emails($this->owner));
    }

    public function test_recipients_come_only_from_the_client_and_are_remembered_for_the_next_month(): void
    {
        $this->draft();
        $this->actingAs($this->owner)->put($this->url(), ['recipients' => ['stranger@else.test']])->assertSessionHasErrors('recipients');
        $this->actingAs($this->owner)->put($this->url(), ['recipients' => ['ANNA@brzozowy-sad.test'], 'bcc_owner' => false])->assertSessionHasNoErrors();
        $this->assertSame(['anna@brzozowy-sad.test'], $this->email()->recipients);

        Carbon::setTestNow('2026-12-02 10:00:00');
        $this->actingAs($this->owner)->post($this->url('2026-11'), [])->assertSessionHasNoErrors();
        $this->assertSame(['anna@brzozowy-sad.test'], $this->email('2026-11')->recipients);
    }

    public function test_a_refresh_keeps_hand_edits_unless_the_owner_confirms_and_keeps_the_recipients(): void
    {
        $this->draft(['anna@brzozowy-sad.test']);
        $this->actingAs($this->owner)->post($this->url(), [])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->put($this->url(), ['subject' => "Ręczny\ntemat", 'body' => 'Ręczny tekst — z myślnikiem'])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('—', (string) $this->email()->body, 'the body is humanized');
        $this->assertSame('Ręczny temat', $this->email()->subject, 'a subject is one line');

        $this->actingAs($this->owner)->post($this->url(), [])->assertSessionHasErrors('template');
        $this->assertSame('Ręczny temat', $this->email()->subject);

        $this->actingAs($this->owner)->post($this->url(), ['replace' => true])->assertSessionHasNoErrors();
        $this->assertSame('Podsumowanie za październik 2026', $this->email()->subject);
        $this->assertSame(['anna@brzozowy-sad.test'], $this->email()->recipients);
        $this->assertNull($this->email()->text_edited_at);
    }

    public function test_a_recipient_change_alone_is_no_hand_edit_of_the_text(): void
    {
        $this->draft(['anna@brzozowy-sad.test']);

        $this->actingAs($this->owner)->post($this->url(), [])->assertSessionHasNoErrors();
    }

    public function test_a_draft_for_a_client_without_a_retainer_fills_the_hours_with_nothing(): void
    {
        MessageTemplate::query()->update(['body' => "Notka.\n\n{hours_summary}"]);

        $this->draft();

        $this->assertSame("Notka.\n", $this->email()->body);
    }

    public function test_a_send_lost_before_it_reached_the_mailer_reads_as_failed_and_may_go_again(): void
    {
        Queue::fake();
        $this->draft(['anna@brzozowy-sad.test']);
        $this->actingAs($this->owner)->post($this->url().'/send');
        Carbon::setTestNow(now()->addMinutes(ClientSummaryEmail::LEASE_MINUTES + 1));

        $shown = $this->actingAs($this->owner)->getJson($this->url())->assertOk()->json('email');

        $this->assertSame(['failed', 'Sending did not start.'], [$shown['status'], $shown['error']]);
        $this->assertSame(ClientSummaryEmail::FAILED, $this->email()->status);
        $this->actingAs($this->owner)->post($this->url().'/send')->assertSessionHasNoErrors();
        $this->assertSame(2, $this->email()->attempt);
    }

    public function test_a_send_lost_after_it_reached_the_mailer_reads_as_unconfirmed(): void
    {
        Queue::fake();
        $this->draft(['anna@brzozowy-sad.test']);
        $this->actingAs($this->owner)->post($this->url().'/send');
        ClientSummaryEmail::query()->update(['handed_off_at' => now()]);
        Carbon::setTestNow(now()->addMinutes(ClientSummaryEmail::LEASE_MINUTES + 1));

        $this->actingAs($this->owner)->get("/month-close/{$this->client->id}?period=2026-10")->assertOk();

        $this->assertSame(ClientSummaryEmail::UNCONFIRMED, $this->email()->status);
    }

    public function test_a_live_sent_email_whose_tick_never_ran_is_ticked_when_the_card_loads(): void
    {
        $this->useCapture(live: true);
        $this->draft(['anna@brzozowy-sad.test']);
        $this->actingAs($this->owner)->post($this->url().'/send');
        ClientSummaryEmail::query()->update(['step_ticked_at' => null, 'tick_error' => null]);
        MonthCloseStep::query()->update(['state' => 'pending', 'completed_at' => null]);

        $steps = $this->actingAs($this->owner)->get("/month-close/{$this->client->id}?period=2026-10")->assertOk()->viewData('page')['props']['client_steps'];

        $this->assertSame('done', collect($steps)->firstWhere('key', 'summary_email')['state'], 'the card shows the tick it just made');
        $this->assertNotNull($this->email()->step_ticked_at);
        $this->assertSame(MonthCloseStep::STATE_DONE, MonthCloseStep::query()->where('step_key', 'summary_email')->sole()->state);
    }

    public function test_recovery_that_loses_to_a_hand_off_reads_the_send_as_unconfirmed_never_failed(): void
    {
        Queue::fake();
        $this->draft(['anna@brzozowy-sad.test']);
        $this->actingAs($this->owner)->post($this->url().'/send');
        Carbon::setTestNow(now()->addMinutes(ClientSummaryEmail::LEASE_MINUTES + 1));
        $stale = $this->email();
        ClientSummaryEmail::query()->update(['handed_off_at' => now()]);

        $recovered = app(SummaryEmails::class)->recover($stale);

        $this->assertSame(ClientSummaryEmail::UNCONFIRMED, $recovered->status);
        $this->assertSame(ClientSummaryEmail::UNCONFIRMED, $this->email()->status);
    }

    public function test_a_job_past_its_lease_never_hands_the_email_to_the_mailer(): void
    {
        Queue::fake();
        $this->draft(['anna@brzozowy-sad.test']);
        $this->actingAs($this->owner)->post($this->url().'/send');
        Carbon::setTestNow(now()->addMinutes(ClientSummaryEmail::LEASE_MINUTES + 1));

        $this->runJob(1);

        $this->assertNull($this->email()->handed_off_at);
        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_a_send_that_recovery_already_called_unconfirmed_neither_ticks_nor_fails_the_job(): void
    {
        Queue::fake();
        $this->draft(['anna@brzozowy-sad.test']);
        $this->actingAs($this->owner)->post($this->url().'/send');
        $this->useTransport(new RecoveredMidSendTransport($this->email()->id));

        $this->runJob(1);

        $this->assertSame(ClientSummaryEmail::UNCONFIRMED, $this->email()->status);
        $this->assertNull($this->email()->step_ticked_at);
        $this->assertSame(0, MonthCloseStep::query()->where('step_key', 'summary_email')->where('state', MonthCloseStep::STATE_DONE)->count());
    }

    public function test_a_tick_from_a_stale_read_does_not_tick_the_step_again(): void
    {
        $this->useCapture(live: true);
        $this->draft(['anna@brzozowy-sad.test']);
        $this->actingAs($this->owner)->post($this->url().'/send');
        $stale = $this->email()->forceFill(['step_ticked_at' => null]);
        Carbon::setTestNow(now()->addHour());

        app(SummaryEmails::class)->tickStep($stale);

        $step = MonthCloseStep::query()->where('step_key', 'summary_email')->sole();
        $this->assertSame('2026-11-03 10:00:00', $step->completed_at?->toDateTimeString(), 'the step keeps its first tick');
    }

    public function test_the_send_job_stops_before_the_queue_would_hand_it_to_another_worker(): void
    {
        $this->assertLessThan((int) config('queue.connections.redis.retry_after'), (new SendSummaryEmail(1, 1))->timeout);
    }

    public function test_a_send_to_the_array_mailer_is_a_test_send_that_neither_locks_nor_ticks(): void
    {
        $this->draft(['anna@brzozowy-sad.test']);

        $this->actingAs($this->owner)->post($this->url().'/send')->assertSessionHasNoErrors();

        $email = $this->email();
        $this->assertSame([ClientSummaryEmail::TEST_SENT, 'test', 1], [$email->status, $email->delivery, $email->attempt]);
        $this->assertNotNull($email->message_id);
        $this->assertTrue($email->isEditable());
        $this->assertNull($email->step_ticked_at);
        $this->assertFalse(MonthCloseStep::query()->where('step_key', 'summary_email')->where('state', 'done')->exists());
        $sent = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertEqualsCanonicalizing(['anna@brzozowy-sad.test', 'owner@agency.test'], $this->recipients($sent->first()));

        $this->actingAs($this->owner)->put($this->url(), ['subject' => 'Poprawiony'])->assertSessionHasNoErrors();
        $this->assertSame(ClientSummaryEmail::DRAFT, $this->email()->status);
    }

    public function test_the_email_keeps_every_line_break_and_never_renders_raw_html(): void
    {
        $this->draft(['anna@brzozowy-sad.test']);
        $this->actingAs($this->owner)->put($this->url(), ['body' => "Pozdrawiam,\nFilip\n\n<script>x</script>"])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post($this->url().'/send')->assertSessionHasNoErrors();

        $email = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertStringContainsString("Pozdrawiam,<br>\nFilip", (string) $email->getHtmlBody());
        $this->assertStringNotContainsString('<script>', (string) $email->getHtmlBody());
        $this->assertStringContainsString("Pozdrawiam,\nFilip", (string) $email->getTextBody());
    }

    public function test_a_send_while_the_redirect_is_set_reaches_only_the_redirect_and_stays_a_test(): void
    {
        $this->useCapture(live: true, redirect: 'safe@internal.test');
        $this->draft(['anna@brzozowy-sad.test', 'biuro@brzozowy-sad.test']);

        $this->actingAs($this->owner)->post($this->url().'/send')->assertSessionHasNoErrors();

        $this->assertSame(ClientSummaryEmail::TEST_SENT, $this->email()->status);
        $this->assertCount(1, $this->capture->messages());
        $this->assertSame(['safe@internal.test'], $this->recipients($this->capture->messages()->first()));
    }

    public function test_a_live_send_is_sent_locked_and_ticks_the_month_close_step(): void
    {
        $this->useCapture(live: true);
        $this->draft(['anna@brzozowy-sad.test']);

        $this->actingAs($this->owner)->post($this->url().'/send')->assertSessionHasNoErrors();

        $email = $this->email();
        $this->assertSame([ClientSummaryEmail::SENT, 'live', $this->owner->id], [$email->status, $email->delivery, $email->sent_by_user_id]);
        $this->assertNotNull($email->sent_at);
        $this->assertNotNull($email->step_ticked_at);
        $this->assertSame(['anna@brzozowy-sad.test', 'owner@agency.test'], $this->recipients($this->capture->messages()->first()));
        $step = MonthCloseStep::query()->where('step_key', 'summary_email')->sole();
        $this->assertSame([MonthCloseStep::STATE_DONE, MonthCloseStep::VIA_WEB, $this->owner->id], [$step->state, $step->completed_via, $step->completed_by]);

        $this->actingAs($this->owner)->put($this->url(), ['subject' => 'Zmiana'])->assertSessionHasErrors();
        $this->actingAs($this->owner)->post($this->url(), [])->assertSessionHasErrors();
        $this->actingAs($this->owner)->post($this->url().'/send')->assertSessionHasErrors();
        $this->assertCount(1, $this->capture->messages(), 'a sent email goes once');
    }

    public function test_a_tick_that_fails_leaves_the_email_sent_and_is_tried_again_on_its_own(): void
    {
        $this->useCapture(live: true);
        $this->client->update(['month_close_type' => null]);
        $this->draft(['anna@brzozowy-sad.test']);

        $this->actingAs($this->owner)->post($this->url().'/send')->assertSessionHasNoErrors();
        $this->assertSame(ClientSummaryEmail::SENT, $this->email()->status);
        $this->assertNull($this->email()->step_ticked_at);
        $this->assertNotNull($this->email()->tick_error);

        $this->client->update(['month_close_type' => 'gig']);
        $this->actingAs($this->owner)->post($this->url().'/tick')->assertSessionHasNoErrors();

        $this->assertNotNull($this->email()->step_ticked_at);
        $this->assertNull($this->email()->tick_error);
        $this->assertCount(1, $this->capture->messages(), 'the retried tick sends nothing');
    }

    public function test_with_live_sending_off_and_a_real_mailer_nothing_goes_out(): void
    {
        $this->useCapture(live: false);
        $this->draft(['anna@brzozowy-sad.test']);

        $this->actingAs($this->owner)->post($this->url().'/send')->assertSessionHasErrors();

        $this->assertSame([ClientSummaryEmail::DRAFT, 0], [$this->email()->status, $this->email()->attempt]);
        $this->assertCount(0, $this->capture->messages());
    }

    public function test_a_failover_mailer_without_a_redirect_calls_no_transport_from_the_click_or_the_job(): void
    {
        config(['mail.mailers.chain' => ['transport' => 'failover', 'mailers' => ['capture', 'log']], 'mail.default' => 'chain', 'mail.live_send' => false, 'mail.to' => ['address' => null, 'name' => null]]);
        Mail::purge();
        $this->draft(['anna@brzozowy-sad.test']);

        $this->actingAs($this->owner)->post($this->url().'/send')->assertSessionHasErrors();
        $this->assertSame([ClientSummaryEmail::DRAFT, 0], [$this->email()->status, $this->email()->attempt]);

        Queue::fake();
        config(['mail.default' => 'array']);
        $this->actingAs($this->owner)->post($this->url().'/send')->assertSessionHasNoErrors();
        config(['mail.default' => 'chain']);
        Mail::purge();
        $this->runJob(1);

        $this->assertSame(ClientSummaryEmail::FAILED, $this->email()->status);
        $this->assertStringContainsString('single transport', (string) $this->email()->error);
        $this->assertCount(0, $this->capture->messages());
    }

    public function test_the_job_rechecks_the_switch_with_its_own_configuration(): void
    {
        Queue::fake();
        $this->useCapture(live: true);
        $this->draft(['anna@brzozowy-sad.test']);
        $this->actingAs($this->owner)->post($this->url().'/send')->assertSessionHasNoErrors();

        config(['mail.live_send' => false]);
        $this->runJob(1);

        $this->assertSame(ClientSummaryEmail::FAILED, $this->email()->status);
        $this->assertCount(0, $this->capture->messages());
    }

    public function test_a_rejection_by_the_mailer_fails_and_may_be_sent_again(): void
    {
        $this->useTransport(new ThrowingTransport(new UnexpectedResponseException('550 mailbox unavailable')));
        $this->draft(['anna@brzozowy-sad.test']);

        $this->actingAs($this->owner)->post($this->url().'/send')->assertSessionHasNoErrors();
        $this->assertSame(ClientSummaryEmail::FAILED, $this->email()->status);
        $this->assertStringContainsString('550', (string) $this->email()->error);

        $this->useCapture(live: true);
        $this->actingAs($this->owner)->post($this->url().'/send')->assertSessionHasNoErrors();
        $this->assertSame([ClientSummaryEmail::SENT, 2], [$this->email()->status, $this->email()->attempt]);
    }

    public function test_an_unknown_outcome_is_unconfirmed_and_only_an_explicit_resend_sends_again(): void
    {
        $this->useTransport(new ThrowingTransport(new RuntimeException('connection reset after DATA')));
        $this->draft(['anna@brzozowy-sad.test']);

        $this->actingAs($this->owner)->post($this->url().'/send')->assertSessionHasNoErrors();
        $this->assertSame(ClientSummaryEmail::UNCONFIRMED, $this->email()->status);

        $this->useCapture(live: true);
        $this->actingAs($this->owner)->put($this->url(), ['subject' => 'x'])->assertSessionHasErrors();
        $this->actingAs($this->owner)->post($this->url().'/send')->assertSessionHasErrors();
        $this->assertCount(0, $this->capture->messages());

        $this->actingAs($this->owner)->post($this->url().'/send', ['resend_unconfirmed' => true])->assertSessionHasNoErrors();
        $this->assertSame(ClientSummaryEmail::SENT, $this->email()->status);
        $this->assertCount(1, $this->capture->messages());
    }

    public function test_the_owner_marks_an_unconfirmed_live_send_as_sent_and_the_step_ticks(): void
    {
        $this->useTransport(new ThrowingTransport(new RuntimeException('timeout')));
        $this->draft(['anna@brzozowy-sad.test']);
        $this->actingAs($this->owner)->post($this->url().'/send');
        $this->assertSame(ClientSummaryEmail::UNCONFIRMED, $this->email()->status);

        $user = User::factory()->create(['account_id' => $this->owner->account_id, 'owner' => false]);
        $this->actingAs($user)->post($this->url().'/mark-sent')->assertNotFound();

        Carbon::setTestNow('2026-11-03 11:30:00');
        $this->actingAs($this->owner)->post($this->url().'/mark-sent')->assertSessionHasNoErrors();

        $email = $this->email();
        $this->assertSame([ClientSummaryEmail::SENT, $this->owner->id, '2026-11-03 11:30:00'], [$email->status, $email->sent_by_user_id, $email->sent_at?->toDateTimeString()]);
        $this->assertNotNull($email->step_ticked_at);
    }

    public function test_an_unconfirmed_test_send_cannot_be_marked_as_sent(): void
    {
        $this->useTransport(new ThrowingTransport(new RuntimeException('timeout')));
        config(['mail.to' => ['address' => 'safe@internal.test', 'name' => null]]);
        Mail::purge();
        $this->draft(['anna@brzozowy-sad.test']);
        $this->actingAs($this->owner)->post($this->url().'/send');
        $this->assertSame(ClientSummaryEmail::UNCONFIRMED, $this->email()->status);

        $this->actingAs($this->owner)->post($this->url().'/mark-sent')->assertSessionHasErrors();
        $this->assertSame(ClientSummaryEmail::UNCONFIRMED, $this->email()->status);
    }

    public function test_the_content_is_frozen_while_queued_and_a_second_click_is_refused(): void
    {
        Queue::fake();
        $this->draft(['anna@brzozowy-sad.test']);

        $this->actingAs($this->owner)->post($this->url().'/send')->assertSessionHasNoErrors();
        Queue::assertPushed(SendSummaryEmail::class, fn (SendSummaryEmail $job): bool => $job->attempt === 1);

        $this->actingAs($this->owner)->put($this->url(), ['subject' => 'Za późno'])->assertSessionHasErrors();
        $this->actingAs($this->owner)->post($this->url().'/send')->assertSessionHasErrors();
        Queue::assertPushed(SendSummaryEmail::class, 1);
        $this->assertSame('Podsumowanie za październik 2026', $this->email()->subject);
    }

    public function test_a_job_seen_twice_after_hand_off_is_unconfirmed_and_sends_nothing_more(): void
    {
        Queue::fake();
        $this->draft(['anna@brzozowy-sad.test']);
        $this->actingAs($this->owner)->post($this->url().'/send');
        ClientSummaryEmail::query()->update(['handed_off_at' => now()]);

        $this->runJob(1);

        $this->assertSame(ClientSummaryEmail::UNCONFIRMED, $this->email()->status);
        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_a_queued_send_that_never_reached_the_mailer_is_claimed_again_and_the_stale_job_does_nothing(): void
    {
        Queue::fake();
        $this->draft(['anna@brzozowy-sad.test']);
        $this->actingAs($this->owner)->post($this->url().'/send')->assertSessionHasNoErrors();

        Carbon::setTestNow(now()->addMinutes(ClientSummaryEmail::LEASE_MINUTES + 1));
        $this->actingAs($this->owner)->post($this->url().'/send')->assertSessionHasNoErrors();
        $this->assertSame(2, $this->email()->attempt);

        $this->runJob(1);
        $this->assertSame(ClientSummaryEmail::QUEUED, $this->email()->status);
        $this->runJob(2);
        $this->runJob(2);

        $this->assertSame(ClientSummaryEmail::TEST_SENT, $this->email()->status);
        $this->assertCount(1, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_a_send_without_recipients_is_refused(): void
    {
        $this->draft();

        $this->actingAs($this->owner)->post($this->url().'/send')->assertSessionHasErrors();
        $this->assertSame(0, $this->email()->attempt);
    }

    public function test_only_the_owner_reaches_the_summary_email_and_only_in_the_own_account(): void
    {
        $this->draft(['anna@brzozowy-sad.test']);
        $user = User::factory()->create(['account_id' => $this->owner->account_id, 'owner' => false]);
        $this->actingAs($user)->getJson($this->url())->assertNotFound();
        $this->actingAs($user)->post($this->url().'/send')->assertNotFound();
        $this->assertRefused(fn () => app(SummaryEmails::class)->send($this->email(), $user));

        $stranger = User::factory()->create(['account_id' => Account::factory()->create()->id, 'owner' => true]);
        $this->actingAs($stranger)->getJson($this->url())->assertNotFound();
        $this->actingAs($stranger)->post($this->url().'/send')->assertNotFound();
        $this->assertSame(ClientSummaryEmail::DRAFT, $this->email()->status);
    }

    public function test_the_summary_email_is_absent_without_billing(): void
    {
        $account = $this->owner->account;
        $account->settings = [...(array) $account->settings, 'features' => ['billing' => false]];
        $account->save();

        $this->actingAs($this->owner)->getJson($this->url())->assertNotFound();
        $this->assertRefused(fn () => app(SummaryEmails::class)->draft($this->client->fresh(), '2026-10'));
    }

    private function url(string $period = '2026-10'): string
    {
        return "/clients/{$this->client->id}/summary-emails/{$period}";
    }

    /** @param  list<string>  $recipients */
    private function draft(array $recipients = []): void
    {
        $this->actingAs($this->owner)->post($this->url(), [])->assertSessionHasNoErrors();
        if ($recipients !== []) {
            $this->actingAs($this->owner)->put($this->url(), ['recipients' => $recipients])->assertSessionHasNoErrors();
        }
    }

    private function email(string $period = '2026-10'): ClientSummaryEmail
    {
        return ClientSummaryEmail::query()->where('client_id', $this->client->id)->where('period', $period)->sole();
    }

    private function runJob(int $attempt): void
    {
        (new SendSummaryEmail($this->email()->id, $attempt))->handle(app(SummaryEmails::class));
    }

    private function useCapture(bool $live, ?string $redirect = null): void
    {
        config(['mail.default' => 'capture', 'mail.live_send' => $live, 'mail.to' => ['address' => $redirect, 'name' => null]]);
        Mail::purge();
    }

    private function useTransport(TransportInterface $transport): void
    {
        Mail::extend('throwing', fn (): TransportInterface => $transport);
        config(['mail.mailers.throwing' => ['transport' => 'throwing'], 'mail.default' => 'throwing', 'mail.live_send' => true]);
        Mail::purge();
    }

    /** @return list<string> */
    private function recipients(SentMessage $message): array
    {
        return array_map(fn (Address $a): string => $a->getAddress(), $message->getEnvelope()->getRecipients());
    }

    private function assertRefused(callable $call): void
    {
        try {
            $call();
            $this->fail('expected a refusal');
        } catch (AgentRefusal) {
            $this->addToAssertionCount(1);
        }
    }
}

final readonly class ThrowingTransport implements TransportInterface
{
    public function __construct(private Throwable $error) {}

    public function __toString(): string
    {
        return 'throwing://';
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        throw $this->error;
    }
}

final readonly class RecoveredMidSendTransport implements TransportInterface
{
    public function __construct(private int $emailId) {}

    public function __toString(): string
    {
        return 'recovered://';
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        ClientSummaryEmail::query()->whereKey($this->emailId)->update(['status' => ClientSummaryEmail::UNCONFIRMED]);

        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }
}
