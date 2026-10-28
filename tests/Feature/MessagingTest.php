<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\MessageCancelTool;
use App\Mcp\Tools\MessageResendTool;
use App\Mcp\Tools\MessagesTool;
use App\Mcp\Tools\MessageTemplateSetTool;
use App\Mcp\Tools\TodayTool;
use App\Models\Account;
use App\Models\Client;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Clients\Fields\ClientFieldDefinitions;
use App\Services\Clients\Fields\ClientFieldWriter;
use App\Services\Messaging\MessageDispatcher;
use App\Services\Messaging\MessageScheduler;
use App\Services\Messaging\MessageSettings;
use App\Services\Messaging\SimulatedSmsProvider;
use App\Services\Messaging\SmsResult;
use App\Services\Messaging\SmsText;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use stdClass;
use Tests\TestCase;

/** Text messages from field changes: a confirmation when a visit is booked, a reminder before it, nothing twice. */
final class MessagingTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.display_timezone' => 'Europe/Warsaw']);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Europe/Warsaw'));

        $this->account = Account::factory()->create();
        config(['agent.local_account' => $this->account->id]);
        User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->client = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Kowalski Dom', 'phone' => '100 100 200']);

        app(ClientFieldDefinitions::class)->define($this->account->id, 'visit_at', ['label' => 'Termin wizji', 'type' => 'datetime']);
        $settings = app(MessageSettings::class);
        $settings->template($this->account->id, 'booked', 'Potwierdzenie', 'Umówiliśmy wizję na {field:visit_at}.');
        $settings->template($this->account->id, 'reminder', 'Przypomnienie', 'Przypominamy o wizji {field:visit_at}.');
        $settings->rule($this->account->id, 'confirm', ['template' => 'booked', 'trigger' => 'field_value', 'field' => 'visit_at', 'expire_minutes' => 120]);
        $settings->rule($this->account->id, 'remind', ['template' => 'reminder', 'trigger' => 'date_offset', 'field' => 'visit_at', 'offset_minutes' => -2880]);
    }

    public function test_booking_a_visit_sends_a_confirmation_now_and_keeps_a_reminder_for_two_days_before(): void
    {
        $this->book('2026-10-20 10:00');

        $counts = app(MessageDispatcher::class)->dispatchDue();

        $this->assertSame([OutboundMessage::SIMULATED => 1], $counts);
        $sent = OutboundMessage::query()->where('status', OutboundMessage::SIMULATED)->sole();
        $this->assertSame('+48100100200', $sent->to);
        $this->assertSame('Umówiliśmy wizję na 20.10.2026, 10:00.', $sent->body, 'Polish letters stay unless the account turns them off');

        $reminder = OutboundMessage::query()->where('status', OutboundMessage::PENDING)->sole();
        $this->assertSame('2026-10-18 10:00', $reminder->scheduled_for->setTimezone('Europe/Warsaw')->format('Y-m-d H:i'));
    }

    public function test_moving_the_visit_moves_its_reminder_and_moving_it_back_revives_the_first(): void
    {
        $this->book('2026-10-20 10:00');
        $this->book('2026-10-25 09:00');

        $pending = OutboundMessage::query()->where('status', OutboundMessage::PENDING)->get();
        $this->assertSame(['2026-10-23 09:00'], $this->reminderTimes($pending));
        $this->assertSame(1, OutboundMessage::query()->where('occurrence', 'like', 'event:%')->where('status', OutboundMessage::PENDING)->count(), 'only the latest confirmation waits');

        $this->book('2026-10-20 10:00');
        $this->assertSame(['2026-10-18 10:00'], $this->reminderTimes(OutboundMessage::query()->where('status', OutboundMessage::PENDING)->get()));
    }

    public function test_clearing_the_visit_supersedes_what_waits(): void
    {
        $this->book('2026-10-20 10:00');
        app(ClientFieldWriter::class)->set($this->client, ['visit_at' => null]);

        $this->assertSame(0, OutboundMessage::query()->where('status', OutboundMessage::PENDING)->where('occurrence', 'like', 'at:%')->count());
    }

    public function test_a_late_evening_booking_is_confirmed_in_the_morning(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 21:30', 'Europe/Warsaw'));
        $this->book('2026-10-20 10:00');

        $confirmation = OutboundMessage::query()->where('occurrence', 'like', 'event:%')->sole();
        $this->assertSame('2026-10-13 08:00', $confirmation->scheduled_for->setTimezone('Europe/Warsaw')->format('Y-m-d H:i'));
        $this->assertSame([], app(MessageDispatcher::class)->dispatchDue());
    }

    public function test_a_message_past_its_window_is_skipped_not_sent_late(): void
    {
        $this->book('2026-10-20 10:00');
        $this->travel(3)->hours();

        $this->assertSame([OutboundMessage::SKIPPED => 1], app(MessageDispatcher::class)->dispatchDue());
        $this->assertSame('expired', OutboundMessage::query()->where('status', OutboundMessage::SKIPPED)->sole()->error);
    }

    public function test_opt_out_a_missing_phone_and_a_missing_value_each_skip_with_the_reason(): void
    {
        $this->book('2026-10-20 10:00');
        app(MessageSettings::class)->optOut($this->client, true);
        app(MessageDispatcher::class)->dispatchDue();
        $this->assertSame('opted_out', OutboundMessage::query()->where('status', OutboundMessage::SKIPPED)->sole()->error);
        app(MessageSettings::class)->optOut($this->client, false);
        $this->assertSame([], app(MessageDispatcher::class)->dispatchDue(), 'texts back on after the send time do not bring a skipped message back');

        $other = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Nowak', 'phone' => null]);
        app(ClientFieldWriter::class)->set($other, ['visit_at' => '2026-10-21 10:00']);
        app(MessageSettings::class)->template($this->account->id, 'booked', null, 'Audytor {assignee_name} przyjedzie {field:visit_at}.');
        $third = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Wiśniewski', 'phone' => '+48 100 000 001']);
        app(ClientFieldWriter::class)->set($third, ['visit_at' => '2026-10-22 10:00']);
        app(MessageDispatcher::class)->dispatchDue();

        $reasons = OutboundMessage::query()->where('status', OutboundMessage::SKIPPED)->pluck('error', 'client_id');
        $this->assertSame('no_phone', $reasons[$other->id]);
        $this->assertSame('missing {assignee_name}', $reasons[$third->id]);
    }

    public function test_a_claimed_message_is_never_sent_twice_or_rescheduled(): void
    {
        $this->book('2026-10-20 10:00');
        $reminder = OutboundMessage::query()->where('occurrence', 'like', 'at:%')->sole();
        OutboundMessage::query()->whereKey($reminder->id)->update(['status' => OutboundMessage::SENDING]);

        $this->book('2026-10-27 10:00');
        $this->assertSame(OutboundMessage::SENDING, $reminder->fresh()->status);

        app(MessageDispatcher::class)->dispatchDue();
        $this->assertSame([], app(MessageDispatcher::class)->dispatchDue());
        $this->assertSame(1, OutboundMessage::query()->where('status', OutboundMessage::SIMULATED)->count());
    }

    public function test_a_provider_error_fails_the_message_and_it_is_not_retried(): void
    {
        $this->app->instance(SimulatedSmsProvider::class, new class extends stdClass implements \App\Services\Messaging\SmsProviderInterface
        {
            public function key(): string
            {
                return 'simulator';
            }

            public function send(Account $account, string $to, string $body, string $reference): SmsResult
            {
                throw new RuntimeException('gateway timeout');
            }
        });
        $this->book('2026-10-20 10:00');

        $this->assertSame([OutboundMessage::FAILED => 1], app(MessageDispatcher::class)->dispatchDue());
        $this->assertSame([], app(MessageDispatcher::class)->dispatchDue());
        $this->assertSame('gateway timeout', OutboundMessage::query()->where('status', OutboundMessage::FAILED)->sole()->error);
    }

    public function test_a_cancelled_reminder_stays_cancelled_when_the_date_comes_back(): void
    {
        $this->book('2026-10-20 10:00');
        $reminder = OutboundMessage::query()->where('occurrence', 'like', 'at:%')->sole();

        CrmServer::tool(MessageCancelTool::class, ['id' => $reminder->id])->assertOk();
        CrmServer::tool(MessageCancelTool::class, ['id' => $reminder->id])->assertHasErrors();
        $this->book('2026-10-25 10:00');
        $this->book('2026-10-20 10:00');

        $this->assertSame(OutboundMessage::CANCELLED, $reminder->fresh()->status);
    }

    public function test_rules_and_templates_are_checked_and_written_through_the_verbs(): void
    {
        Artisan::call('crm:message-rule-set', ['key' => 'fresh', '--template' => 'booked', '--trigger' => 'field_value', '--field' => 'visit_at', '--json' => true]);
        $fresh = json_decode(Artisan::output(), true)['rule'];
        $this->assertSame([true, 900], [$fresh['enabled'], $fresh['expire_minutes']], 'a new confirmation rule reads on, and a late booking still goes out the next morning');

        CrmServer::tool(MessageTemplateSetTool::class, ['key' => 'bad', 'name' => 'Zły', 'body' => 'Wizja {field:nope}'])->assertHasErrors()->assertSee('visit_at');

        $this->assertSame(0, Artisan::call('crm:message-rule-set', ['key' => 'remind', '--offset' => '-1d', '--at' => '18:00']));
        $this->book('2026-10-20 10:00');

        $this->assertSame(['2026-10-19 18:00'], $this->reminderTimes(OutboundMessage::query()->where('status', OutboundMessage::PENDING)->get()));
    }

    public function test_another_accounts_messages_cannot_be_seen_or_cancelled(): void
    {
        $this->book('2026-10-20 10:00');
        $message = OutboundMessage::query()->firstOrFail();
        $other = Account::factory()->create();
        config(['agent.local_account' => $other->id]);

        CrmServer::tool(MessagesTool::class, [])->assertOk()->assertDontSee('Kowalski');
        CrmServer::tool(MessageCancelTool::class, ['id' => $message->id])->assertHasErrors();
        $this->assertSame(OutboundMessage::PENDING, $message->fresh()->status);
    }

    public function test_switching_a_rule_off_supersedes_its_pending_reminders(): void
    {
        $this->book('2026-10-20 10:00');
        app(MessageSettings::class)->rule($this->account->id, 'remind', ['enabled' => false]);

        $this->assertSame(0, OutboundMessage::query()->where('occurrence', 'like', 'at:%')->where('status', OutboundMessage::PENDING)->count());
        app(MessageScheduler::class)->recompute($this->client->fresh());
        $this->assertSame(0, OutboundMessage::query()->where('occurrence', 'like', 'at:%')->where('status', OutboundMessage::PENDING)->count());
    }

    public function test_clearing_the_value_withdraws_a_confirmation_still_waiting(): void
    {
        app(MessageSettings::class)->template($this->account->id, 'booked', null, 'Dziękujemy, wizja umówiona.');
        $this->travelTo(CarbonImmutable::parse('2026-10-12 21:30', 'Europe/Warsaw'));
        $this->book('2026-10-20 10:00');
        app(ClientFieldWriter::class)->set($this->client, ['visit_at' => null]);

        $this->travelTo(CarbonImmutable::parse('2026-10-13 08:01', 'Europe/Warsaw'));
        $this->assertSame([], app(MessageDispatcher::class)->dispatchDue());
    }

    public function test_quiet_hours_end_at_eight_on_the_night_the_clocks_go_back(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-24 22:00', 'Europe/Warsaw'));
        $this->book('2026-11-02 10:00');

        $confirmation = OutboundMessage::query()->where('occurrence', 'like', 'event:%')->sole();
        $this->assertSame('2026-10-25 08:00', $confirmation->scheduled_for->setTimezone('Europe/Warsaw')->format('Y-m-d H:i'));
    }

    public function test_a_reminder_claimed_as_the_visit_moves_is_skipped_not_sent_with_the_new_date(): void
    {
        $this->book('2026-10-14 10:00');
        $reminder = OutboundMessage::query()->where('occurrence', 'like', 'at:%')->sole();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:05', 'Europe/Warsaw'));
        OutboundMessage::query()->whereKey($reminder->id)->update(['scheduled_for' => now()->subMinute()]);
        // The visit moves between the claim and the send: the row no longer matches the field.
        $this->client->forceFill(['custom_fields' => ['visit_at' => '2026-10-30T09:00:00Z']])->saveQuietly();

        app(MessageDispatcher::class)->dispatchDue();
        $this->assertSame(['skipped', 'stale'], [$reminder->fresh()->status, $reminder->fresh()->error]);
    }

    public function test_a_claim_that_never_finished_is_reported_as_failed_and_not_sent(): void
    {
        $this->book('2026-10-20 10:00');
        OutboundMessage::query()->where('occurrence', 'like', 'event:%')->update(['status' => OutboundMessage::SENDING, 'claimed_at' => now()]);
        $this->travel(20)->minutes();

        app(MessageDispatcher::class)->dispatchDue();
        $lost = OutboundMessage::query()->where('occurrence', 'like', 'event:%')->sole();
        $this->assertSame(OutboundMessage::FAILED, $lost->status);
        $this->assertStringStartsWith('lost', $lost->error);
    }

    public function test_an_archived_field_sends_nothing_more(): void
    {
        $this->book('2026-10-20 10:00');
        app(ClientFieldDefinitions::class)->define($this->account->id, 'visit_at', ['archived' => true]);

        app(MessageDispatcher::class)->dispatchDue();
        $this->assertSame('field_archived', OutboundMessage::query()->where('occurrence', 'like', 'event:%')->sole()->error);
    }

    public function test_a_rule_change_recomputes_every_client_of_the_account(): void
    {
        $second = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Nowak', 'phone' => '100 000 001']);
        $this->book('2026-10-20 10:00');
        app(ClientFieldWriter::class)->set($second, ['visit_at' => '2026-10-22 12:00']);

        app(MessageSettings::class)->rule($this->account->id, 'remind', ['offset_minutes' => -1440]);

        $times = OutboundMessage::query()->where('status', OutboundMessage::PENDING)->where('occurrence', 'like', 'at:%')->orderBy('client_id')->get();
        $this->assertSame(['2026-10-19 10:00', '2026-10-21 12:00'], $this->reminderTimes($times));
    }

    public function test_a_message_reached_after_quiet_hours_began_waits_for_the_morning(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 19:59', 'Europe/Warsaw'));
        $this->book('2026-10-20 10:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-12 20:01', 'Europe/Warsaw'));
        $this->assertSame([OutboundMessage::SKIPPED => 1], app(MessageDispatcher::class)->dispatchDue(), 'the morning is past the default two-hour window');

        app(MessageSettings::class)->rule($this->account->id, 'confirm', ['expire_minutes' => 900]);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 19:59', 'Europe/Warsaw'));
        $this->book('2026-10-21 10:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-12 20:01', 'Europe/Warsaw'));
        $this->assertSame([OutboundMessage::PENDING => 1], app(MessageDispatcher::class)->dispatchDue());
        $confirmation = OutboundMessage::query()->where('occurrence', 'like', 'event:%')->where('status', OutboundMessage::PENDING)->sole();
        $this->assertSame('2026-10-13 08:00', $confirmation->scheduled_for->setTimezone('Europe/Warsaw')->format('Y-m-d H:i'));
    }

    public function test_a_due_reminder_survives_an_unrelated_edit_before_the_next_run(): void
    {
        app(ClientFieldDefinitions::class)->define($this->account->id, 'note', ['label' => 'Uwagi', 'type' => 'text']);
        $this->book('2026-10-20 10:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00:30', 'Europe/Warsaw'));

        app(ClientFieldWriter::class)->set($this->client, ['note' => 'Brama od podwórza']);
        app(MessageSettings::class)->rule($this->account->id, 'remind', ['expire_minutes' => 120]);

        app(MessageDispatcher::class)->dispatchDue();
        $this->assertSame(OutboundMessage::SIMULATED, OutboundMessage::query()->where('occurrence', 'like', 'at:%')->sole()->status);
    }

    public function test_a_reminder_deferred_by_quiet_hours_survives_an_unrelated_edit(): void
    {
        app(ClientFieldDefinitions::class)->define($this->account->id, 'note', ['label' => 'Uwagi', 'type' => 'text']);
        app(MessageSettings::class)->rule($this->account->id, 'remind', ['at' => '19:59', 'expire_minutes' => 900]);
        $this->book('2026-10-20 10:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-18 20:01', 'Europe/Warsaw'));
        app(MessageDispatcher::class)->dispatchDue();

        app(ClientFieldWriter::class)->set($this->client, ['note' => 'Brama od podwórza']);

        $reminder = OutboundMessage::query()->where('occurrence', 'like', 'at:%')->sole();
        $this->assertSame([OutboundMessage::PENDING, '2026-10-19 08:00'], [$reminder->status, $reminder->scheduled_for->setTimezone('Europe/Warsaw')->format('Y-m-d H:i')]);
    }

    public function test_archiving_the_date_field_withdraws_its_reminders(): void
    {
        $this->book('2026-10-20 10:00');
        app(ClientFieldDefinitions::class)->define($this->account->id, 'visit_at', ['archived' => true]);

        $this->assertSame(OutboundMessage::SUPERSEDED, OutboundMessage::query()->where('occurrence', 'like', 'at:%')->sole()->status);
    }

    public function test_a_rule_change_into_the_past_withdraws_the_reminder_still_waiting(): void
    {
        $this->book('2026-10-20 10:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00', 'Europe/Warsaw'));

        app(MessageSettings::class)->rule($this->account->id, 'remind', ['offset_minutes' => -5760]);

        $this->assertSame(0, OutboundMessage::query()->where('occurrence', 'like', 'at:%')->where('status', OutboundMessage::PENDING)->count());
    }

    public function test_an_archived_field_never_reaches_a_message_and_the_sent_text_passes_the_punctuation_gate(): void
    {
        app(ClientFieldDefinitions::class)->define($this->account->id, 'note', ['label' => 'Uwagi', 'type' => 'text']);
        app(MessageSettings::class)->template($this->account->id, 'booked', null, 'Wizja {field:visit_at}. {field:note}');
        app(ClientFieldWriter::class)->set($this->client, ['note' => 'Brama od podwórza — dzwonić']);
        $this->book('2026-10-20 10:00');
        app(MessageDispatcher::class)->dispatchDue();
        $this->assertStringNotContainsString('—', (string) OutboundMessage::query()->where('status', OutboundMessage::SIMULATED)->sole()->body);

        app(ClientFieldDefinitions::class)->define($this->account->id, 'note', ['archived' => true]);
        $this->book('2026-10-21 10:00');
        app(MessageDispatcher::class)->dispatchDue();
        $this->assertSame('missing {field:note}', OutboundMessage::query()->where('status', OutboundMessage::SKIPPED)->sole()->error);
    }

    public function test_an_account_that_turns_polish_letters_off_sends_them_spelled_plainly(): void
    {
        $this->assertSame(['quiet_from' => '20:00', 'quiet_to' => '08:00', 'ascii' => true, 'provider' => 'simulator'], app(MessageSettings::class)->sending($this->account->id, ascii: true));
        $this->book('2026-10-20 10:00');
        app(MessageDispatcher::class)->dispatchDue();

        $this->assertSame('Umowilismy wizje na 20.10.2026, 10:00.', OutboundMessage::query()->where('status', OutboundMessage::SIMULATED)->sole()->body);
    }

    public function test_sms_length_counts_plain_and_polish_text_and_spells_polish_letters_plainly(): void
    {
        $this->assertSame(['characters' => 160, 'segments' => 1, 'unicode' => false], SmsText::measure(str_repeat('a', 160)));
        $this->assertSame(2, SmsText::measure(str_repeat('a', 161))['segments']);
        $this->assertSame(['characters' => 70, 'segments' => 1, 'unicode' => true], SmsText::measure(str_repeat('ą', 70)));
        $this->assertSame(2, SmsText::measure(str_repeat('ą', 71))['segments']);
        $this->assertSame(['characters' => 4, 'segments' => 1, 'unicode' => false], SmsText::measure('{}'), 'braces take two places each');
        $this->assertSame('Zazolc gesla jazn, cafe', SmsText::transliterate('Zażółć gęślą jaźń, cafe'));
        $this->assertSame('Café', SmsText::transliterate('Café'), 'a letter the GSM alphabet has stays');
    }

    public function test_quiet_hours_moved_later_keep_a_waiting_reminder_and_hold_it_until_they_end(): void
    {
        app(MessageSettings::class)->rule($this->account->id, 'remind', ['at' => '07:30', 'expire_minutes' => 120]);
        $this->book('2026-10-20 10:00');
        $reminder = OutboundMessage::query()->where('occurrence', 'like', 'at:%')->sole();
        $this->assertSame('2026-10-18 08:00', $reminder->scheduled_for->setTimezone('Europe/Warsaw')->format('Y-m-d H:i'));

        $this->travelTo(CarbonImmutable::parse('2026-10-18 07:45', 'Europe/Warsaw'));
        Artisan::call('crm:message-sending', ['--quiet-to' => '09:00', '--json' => true]);
        $this->assertSame('09:00', json_decode(Artisan::output(), true)['sending']['quiet_to']);
        $this->assertSame(OutboundMessage::PENDING, $reminder->fresh()->status, 'the change withdraws nothing');

        $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00', 'Europe/Warsaw'));
        app(MessageDispatcher::class)->dispatchDue();
        $this->assertSame('2026-10-18 09:00', $reminder->fresh()->scheduled_for->setTimezone('Europe/Warsaw')->format('Y-m-d H:i'));

        $this->travelTo(CarbonImmutable::parse('2026-10-18 09:00', 'Europe/Warsaw'));
        $this->assertSame([OutboundMessage::SIMULATED => 1], app(MessageDispatcher::class)->dispatchDue());

        $this->assertSame(1, Artisan::call('crm:message-sending', ['--quiet-from' => '25:00']));
    }

    public function test_a_deleted_rule_withdraws_what_waits_and_a_template_in_use_stays(): void
    {
        $this->book('2026-10-20 10:00');
        app(MessageDispatcher::class)->dispatchDue();

        $this->assertSame(1, Artisan::call('crm:message-template-set', ['key' => 'reminder', '--delete' => true]), 'a rule still uses it');
        $this->assertSame(0, Artisan::call('crm:message-rule-set', ['key' => 'remind', '--delete' => true]));
        $this->assertSame(0, OutboundMessage::query()->where('status', OutboundMessage::PENDING)->count());
        $this->assertSame(0, Artisan::call('crm:message-template-set', ['key' => 'reminder', '--delete' => true]));

        $this->assertSame(1, OutboundMessage::query()->where('status', OutboundMessage::SIMULATED)->count(), 'the sent confirmation stays in the history');
    }

    public function test_the_preview_verb_and_tool_say_what_a_client_would_get(): void
    {
        $this->book('2026-10-20 10:00');

        Artisan::call('crm:message-preview', ['body' => 'Wizja {field:visit_at}.', '--json' => true]);
        $this->assertSame('Wizja 20.10.2026, 10:00.', json_decode(Artisan::output(), true)['preview']['text']);
        CrmServer::tool(\App\Mcp\Tools\MessagePreviewTool::class, ['body' => 'Zażółć'])->assertOk()->assertSee('Zażółć');
        $this->assertSame(1, Artisan::call('crm:message-preview', ['body' => '{field:nope}']));
    }

    public function test_a_preview_fills_the_template_from_a_client_and_counts_the_messages(): void
    {
        $this->book('2026-10-20 10:00');
        $preview = app(MessageSettings::class)->preview($this->account->id, 'Wizja {field:visit_at}, {assignee_name}.');

        $this->assertSame(['text' => 'Wizja 20.10.2026, 10:00, {assignee_name}.', 'client' => 'Kowalski Dom', 'characters' => 43, 'segments' => 1, 'unicode' => false], $preview);
    }

    public function test_a_failed_message_sent_again_goes_to_the_clients_current_number_and_keeps_the_failure(): void
    {
        $failed = $this->failedConfirmation('SMSAPI 13: Invalid phone number');
        $this->client->update(['phone' => '100 999 888']);

        $resent = app(MessageSettings::class)->resend($this->account->id, $failed->id);

        $this->assertSame([OutboundMessage::PENDING, null, null, 1, 'SMSAPI 13: Invalid phone number', null], [$resent->status, $resent->to, $resent->body, $resent->resends, $resent->previous_error, $resent->error]);
        $this->assertSame([OutboundMessage::SIMULATED => 1], app(MessageDispatcher::class)->dispatchDue());
        $sent = $failed->fresh();
        $this->assertSame(['+48100999888', 'Umówiliśmy wizję na 20.10.2026, 10:00.', 1, 'SMSAPI 13: Invalid phone number'], [$sent->to, $sent->body, $sent->resends, $sent->previous_error]);
        $this->assertSame(1, OutboundMessage::query()->where('occurrence', $failed->occurrence)->count(), 'the same row, so the unique key holds');
    }

    public function test_a_message_sent_again_long_after_its_window_still_goes_and_quiet_hours_and_opt_out_still_apply(): void
    {
        $failed = $this->failedConfirmation('no answer from SMSAPI, the message may have gone out: timed out');
        $this->travelTo(CarbonImmutable::parse('2026-10-13 22:30', 'Europe/Warsaw'));

        app(MessageSettings::class)->resend($this->account->id, $failed->id);
        $this->assertSame([OutboundMessage::PENDING => 1], app(MessageDispatcher::class)->dispatchDue(), 'quiet hours hold it');
        $this->assertSame('2026-10-14 08:00', $failed->fresh()->scheduled_for->setTimezone('Europe/Warsaw')->format('Y-m-d H:i'));

        app(MessageSettings::class)->optOut($this->client, true);
        $this->travelTo(CarbonImmutable::parse('2026-10-14 08:01', 'Europe/Warsaw'));
        app(MessageDispatcher::class)->dispatchDue();
        $this->assertSame([OutboundMessage::SKIPPED, 'opted_out'], [$failed->fresh()->status, $failed->fresh()->error]);
    }

    public function test_only_a_failed_message_is_sent_again_through_the_verb_and_the_tool(): void
    {
        $this->book('2026-10-20 10:00');
        $pending = OutboundMessage::query()->where('occurrence', 'like', 'at:%')->sole();
        $this->assertSame(1, Artisan::call('crm:message-resend', ['id' => $pending->id]));
        CrmServer::tool(MessageResendTool::class, ['id' => $pending->id])->assertHasErrors()->assertSee('only a failed message');

        $failed = $this->failedConfirmation('SMSAPI 98: inactive account');
        $this->assertSame(0, Artisan::call('crm:message-resend', ['id' => $failed->id, '--json' => true]));
        $this->assertSame(['id' => $failed->id, 'status' => 'pending', 'resends' => 1, 'previous_error' => 'SMSAPI 98: inactive account'], json_decode(Artisan::output(), true));

        OutboundMessage::query()->whereKey($failed->id)->update(['status' => OutboundMessage::FAILED, 'error' => 'SMSAPI 98: inactive account']);
        CrmServer::tool(MessageResendTool::class, ['id' => $failed->id])->assertOk()->assertSee('"resends":2');
    }

    public function test_another_accounts_failed_message_cannot_be_sent_again(): void
    {
        $failed = $this->failedConfirmation('SMSAPI 13: Invalid phone number');
        config(['agent.local_account' => Account::factory()->create()->id]);

        CrmServer::tool(MessageResendTool::class, ['id' => $failed->id])->assertHasErrors();
        $this->assertSame(1, Artisan::call('crm:message-resend', ['id' => $failed->id]));
        $this->assertSame(OutboundMessage::FAILED, $failed->fresh()->status);
    }

    public function test_a_message_whose_rule_is_off_is_not_sent_again(): void
    {
        $failed = $this->failedConfirmation('SMSAPI 13: Invalid phone number');
        app(MessageSettings::class)->rule($this->account->id, 'confirm', ['enabled' => false]);

        CrmServer::tool(MessageResendTool::class, ['id' => $failed->id])->assertHasErrors()->assertSee('turn it on');
        $this->assertSame(OutboundMessage::FAILED, $failed->fresh()->status);
    }

    public function test_a_failed_message_can_be_cancelled_and_then_stays_cancelled(): void
    {
        $failed = $this->failedConfirmation('SMSAPI 13: Invalid phone number');

        CrmServer::tool(MessageCancelTool::class, ['id' => $failed->id])->assertOk();

        $this->assertSame([OutboundMessage::CANCELLED, 'SMSAPI 13: Invalid phone number'], [$failed->fresh()->status, $failed->fresh()->error]);
        CrmServer::tool(MessageResendTool::class, ['id' => $failed->id])->assertHasErrors();
    }

    public function test_a_reminder_sent_again_survives_an_unrelated_edit_before_the_next_run(): void
    {
        $this->book('2026-10-14 12:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-12 12:05', 'Europe/Warsaw'));
        $reminder = OutboundMessage::query()->where('occurrence', 'like', 'at:%')->sole();
        OutboundMessage::query()->whereKey($reminder->id)->update(['status' => OutboundMessage::FAILED, 'error' => 'lost: the sending run stopped before recording the outcome']);
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'Europe/Warsaw'));

        app(MessageSettings::class)->resend($this->account->id, $reminder->id);
        app(ClientFieldWriter::class)->set($this->client->fresh(), ['visit_at' => '2026-10-14 12:00']);
        app(MessageScheduler::class)->recompute($this->client->fresh());

        $this->assertSame(OutboundMessage::PENDING, $reminder->fresh()->status);
    }

    public function test_today_lists_failed_messages_and_says_which_may_have_gone_out(): void
    {
        $failed = $this->failedConfirmation('no answer from SMSAPI, the message may have gone out: timed out');

        CrmServer::tool(TodayTool::class, [])->assertOk()->assertSee('"may_have_gone_out":true')->assertSee('Kowalski Dom');
        $this->assertSame(0, Artisan::call('crm:today'));
        $this->assertStringContainsString("#{$failed->id}", Artisan::output());
        $this->assertFalse(OutboundMessage::mayHaveGoneOut('SMSAPI 13: Invalid phone number'));
        $this->assertTrue(OutboundMessage::mayHaveGoneOut('lost: the sending run stopped before recording the outcome'));
    }

    private function failedConfirmation(string $error): OutboundMessage
    {
        $this->book('2026-10-20 10:00');
        $confirmation = OutboundMessage::query()->where('occurrence', 'like', 'event:%')->sole();
        app(MessageDispatcher::class)->dispatchDue();
        OutboundMessage::query()->whereKey($confirmation->id)->update(['status' => OutboundMessage::FAILED, 'error' => $error, 'sent_at' => null]);

        return $confirmation->fresh();
    }

    private function book(string $at): void
    {
        app(ClientFieldWriter::class)->set($this->client, ['visit_at' => $at]);
    }

    /** @param iterable<OutboundMessage> $messages */
    private function reminderTimes(iterable $messages): array
    {
        return collect($messages)->filter(fn (OutboundMessage $m): bool => str_starts_with($m->occurrence, 'at:'))
            ->map(fn (OutboundMessage $m): string => $m->scheduled_for->setTimezone('Europe/Warsaw')->format('Y-m-d H:i'))->values()->all();
    }
}
