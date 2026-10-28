<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientFieldEvent;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Clients\Fields\ClientFieldDefinitions;
use App\Services\Clients\Fields\ClientFieldWriter;
use App\Services\Messaging\MessageSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** A client's fields, assignee and text messages on Przegląd and Dane, edited through the writer the verbs use. */
final class ClientFieldScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.display_timezone' => 'Europe/Warsaw']);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Europe/Warsaw'));
        $account = Account::factory()->create(['settings' => ['features' => ['billing' => false]]]);
        $this->user = User::factory()->create(['account_id' => $account->id, 'owner' => true, 'first_name' => 'Marta', 'last_name' => 'Lis']);
        $this->client = Client::factory()->create(['account_id' => $account->id, 'name' => 'Jan Kowalczyk', 'phone' => '100100200']);

        $definitions = app(ClientFieldDefinitions::class);
        $definitions->define($account->id, 'visit_status', ['label' => 'Wizja', 'type' => 'select', 'options' => [['key' => 'booked', 'label' => 'Umówiona']], 'on_overview' => true]);
        $definitions->define($account->id, 'visit_at', ['label' => 'Termin wizji', 'type' => 'datetime', 'on_overview' => true]);
        $definitions->define($account->id, 'note', ['label' => 'Uwagi', 'type' => 'text']);
        $settings = app(MessageSettings::class);
        $settings->template($account->id, 'reminder', 'Przypomnienie', 'Wizja {field:visit_at}.');
        $settings->rule($account->id, 'remind', ['template' => 'reminder', 'trigger' => 'date_offset', 'field' => 'visit_at', 'offset_minutes' => -1440, 'at' => '18:00']);
    }

    public function test_dane_shows_every_field_readably_with_the_history_and_no_billing_tab(): void
    {
        app(ClientFieldWriter::class)->set($this->client, ['visit_status' => 'booked', 'visit_at' => '2026-10-16 10:00'], $this->user);

        $this->actingAs($this->user)->get("/clients/{$this->client->id}/dane")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('client.billing', false)
                ->where('card.fields.1.display', '16.10.2026, 10:00')
                ->where('card.fields.1.input', '2026-10-16T10:00')
                ->where('card.fields.2.display', null)
                ->where('field_history.0.user', 'Marta Lis')
                ->has('field_history', 2));
    }

    public function test_a_waiting_message_reads_as_held_while_the_client_gets_no_texts(): void
    {
        app(ClientFieldWriter::class)->set($this->client, ['visit_at' => '2026-10-16 10:00'], $this->user);
        $this->actingAs($this->user)->get("/clients/{$this->client->id}/przeglad")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('overview.sms.0.status', 'pending')->where('overview.sms.0.held', false));

        $this->actingAs($this->user)->patch("/clients/{$this->client->id}/fields", ['sms_opt_out' => true])->assertSessionHasNoErrors();

        $this->actingAs($this->user)->get("/clients/{$this->client->id}/przeglad")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('overview.sms.0.status', 'pending')->where('overview.sms.0.held', true));
        $this->actingAs($this->user)->get('/sms')->assertInertia(fn (AssertableInertia $page) => $page->where('messages.data.0.held', true));
    }

    public function test_klienci_counts_only_the_texts_that_will_go_today(): void
    {
        $settings = app(MessageSettings::class);
        $settings->template($this->client->account_id, 'booked', 'Umówiona', 'Wizja umówiona.');
        $settings->rule($this->client->account_id, 'confirm', ['template' => 'booked', 'trigger' => 'field_value', 'field' => 'visit_status', 'values' => ['booked']]);
        app(ClientFieldWriter::class)->set($this->client, ['visit_status' => 'booked'], $this->user);
        $this->actingAs($this->user)->get('/clients')->assertInertia(fn (AssertableInertia $page) => $page->where('card.sms.today', 1));

        $this->actingAs($this->user)->patch("/clients/{$this->client->id}/fields", ['sms_opt_out' => true])->assertSessionHasNoErrors();

        $this->actingAs($this->user)->get('/clients')->assertInertia(fn (AssertableInertia $page) => $page->where('card.sms.today', 0));
    }

    public function test_przeglad_shows_the_overview_fields_and_the_scheduled_text_instead_of_hours(): void
    {
        app(ClientFieldWriter::class)->set($this->client, ['visit_at' => '2026-10-16 10:00']);

        $this->actingAs($this->user)->get("/clients/{$this->client->id}/przeglad")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('overview.card.fields', 2)
                ->where('overview.hours', null)
                ->where('overview.month_close', null)
                ->where('overview.sms.0.status', 'pending')
                ->where('overview.sms.0.body', 'Wizja 16.10.2026, 10:00.')
                ->where('overview.sms.0.time', '18:00'));
    }

    public function test_the_dialog_writes_changed_fields_the_assignee_and_the_opt_out_together(): void
    {
        $this->actingAs($this->user)->patch("/clients/{$this->client->id}/fields", [
            'values' => ['visit_at' => '2026-10-16T10:00', 'note' => 'Kotłownia w piwnicy'],
            'assignee_id' => $this->user->id,
            'sms_opt_out' => true,
        ])->assertSessionHasNoErrors();

        $this->client->refresh();
        $this->assertSame('2026-10-16T08:00:00Z', $this->client->custom_fields['visit_at']);
        $this->assertSame([$this->user->id, true], [$this->client->assigned_user_id, $this->client->sms_opt_out]);
        $this->assertSame(3, ClientFieldEvent::query()->where('client_id', $this->client->id)->count());
        $this->assertSame(1, OutboundMessage::query()->where('status', OutboundMessage::PENDING)->count());
    }

    public function test_a_bad_value_comes_back_on_its_field_and_another_accounts_client_is_not_found(): void
    {
        $this->actingAs($this->user)->patch("/clients/{$this->client->id}/fields", ['values' => ['visit_at' => 'jutro']])->assertSessionHasErrors('visit_at');

        $other = Client::factory()->create(['account_id' => Account::factory()->create()->id]);
        $this->actingAs($this->user)->patch("/clients/{$other->id}/fields", ['sms_opt_out' => true])->assertNotFound();
    }

    public function test_a_scheduled_text_is_cancelled_from_the_record_and_only_in_its_own_account(): void
    {
        app(ClientFieldWriter::class)->set($this->client, ['visit_at' => '2026-10-16 10:00']);
        $message = OutboundMessage::query()->sole();

        $intruder = User::factory()->create(['account_id' => Account::factory()->create()->id]);
        $this->actingAs($intruder)->post("/messages/{$message->id}/cancel")->assertSessionHasErrors('form');
        $this->actingAs($this->user)->post("/messages/{$message->id}/cancel")->assertSessionHasNoErrors();

        $this->assertSame(OutboundMessage::CANCELLED, $message->fresh()->status);
    }

    public function test_a_failed_text_is_sent_again_from_the_record_and_only_in_its_own_account(): void
    {
        $message = $this->failedReminder('SMSAPI 13: Invalid phone number');

        $intruder = User::factory()->create(['account_id' => Account::factory()->create()->id]);
        $this->actingAs($intruder)->post("/messages/{$message->id}/resend")->assertSessionHasErrors('form');
        $this->assertSame(OutboundMessage::FAILED, $message->fresh()->status);

        $this->actingAs($this->user)->from("/clients/{$this->client->id}/przeglad")->post("/messages/{$message->id}/resend")
            ->assertRedirect("/clients/{$this->client->id}/przeglad")->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame([OutboundMessage::PENDING, 1], [$message->fresh()->status, $message->fresh()->resends]);
        $this->actingAs($this->user)->post("/messages/{$message->id}/resend")->assertSessionHasErrors('form');
    }

    public function test_przeglad_lists_a_failed_text_with_its_reason_and_counts_it_beside_what_waits(): void
    {
        $failed = $this->failedReminder('no answer from SMSAPI, the message may have gone out: timed out');
        app(ClientFieldWriter::class)->set($this->client->fresh(), ['visit_at' => '2026-10-20 10:00']);

        $this->actingAs($this->user)->get("/clients/{$this->client->id}/przeglad")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('overview.sms.0.status', 'pending')
                ->where('overview.sms.1.id', $failed->id)
                ->where('overview.sms.1.status', 'failed')
                ->where('overview.sms.1.may_have_gone_out', true)
                ->where('overview.sms_failed', 1)
                ->where('overview.sms_pending', 1)
                ->where('overview.sms_live', true));
    }

    public function test_przeglad_keeps_what_went_out_today_and_stops_asking_for_updates_once_nothing_waits(): void
    {
        app(ClientFieldWriter::class)->set($this->client, ['visit_at' => '2026-10-16 10:00']);
        $message = OutboundMessage::query()->sole();
        OutboundMessage::query()->whereKey($message->id)->update(['status' => OutboundMessage::SENT, 'sent_at' => now()]);

        $this->actingAs($this->user)->get("/clients/{$this->client->id}/przeglad")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('overview.sms.0.status', 'sent')->where('overview.sms_live', false)->where('overview.sms_pending', 0));

        $this->travelTo(CarbonImmutable::parse('2026-10-13 09:00', 'Europe/Warsaw'));
        $this->actingAs($this->user)->get("/clients/{$this->client->id}/przeglad")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('overview.sms', []));
    }

    public function test_the_przeglad_poll_reloads_the_overview_without_the_header(): void
    {
        app(ClientFieldWriter::class)->set($this->client, ['visit_at' => '2026-10-16 10:00']);
        $version = $this->actingAs($this->user)->get("/clients/{$this->client->id}/przeglad")->viewData('page')['version'];

        $this->actingAs($this->user)->get("/clients/{$this->client->id}/przeglad", [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'clients/show',
            'X-Inertia-Partial-Data' => 'overview',
        ])->assertOk()->assertJsonPath('props.overview.sms.0.status', 'pending')->assertJsonMissingPath('props.client')->assertJsonPath('props.flash', fn ($flash): bool => is_array($flash));
    }

    public function test_the_queue_and_dziennik_say_why_a_text_failed(): void
    {
        $this->failedReminder('SMSAPI 13: Invalid phone number');

        $this->actingAs($this->user)->get('/sms?status=failed')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('messages.data.0.error', 'SMSAPI 13: Invalid phone number')->where('messages.data.0.may_have_gone_out', false));
        $this->actingAs($this->user)->get("/clients/{$this->client->id}/dziennik")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('journal_sms.0.status', 'failed')->where('journal_sms.0.resends', 0));
    }

    public function test_dziennik_lists_the_text_messages_and_puts_field_changes_in_the_timeline(): void
    {
        app(ClientFieldWriter::class)->set($this->client, ['visit_status' => 'booked', 'visit_at' => '2026-10-16 10:00'], $this->user);

        $this->actingAs($this->user)->get("/clients/{$this->client->id}/dziennik")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('journal_sms.0.status', 'pending')
                ->where('journal.summary.field_changes', 2)
                ->where('journal_notes.rows', fn ($rows): bool => collect($rows)->where('kind', 'field')->pluck('new')->sort()->values()->all() === ['16.10.2026, 10:00', 'Umówiona']
                    && collect($rows)->where('kind', 'field')->every(fn (array $row): bool => $row['author'] === 'Marta Lis')));
    }

    public function test_the_queue_shows_what_waits_first_and_filters_by_status_text_and_client(): void
    {
        app(ClientFieldWriter::class)->set($this->client, ['visit_at' => '2026-10-16 10:00']);
        $other = Client::factory()->create(['account_id' => $this->client->account_id, 'name' => 'Piotr Mazur', 'phone' => '600200300']);
        app(ClientFieldWriter::class)->set($other, ['visit_at' => '2026-10-20 10:00']);
        OutboundMessage::query()->where('client_id', $other->id)->update(['status' => OutboundMessage::CANCELLED, 'scheduled_for' => now()->subDays(3)]);

        $this->actingAs($this->user)->get('/sms')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('messages/index')
                ->where('messages.total', 2)
                ->where('messages.data.0.client', 'Jan Kowalczyk')
                ->where('messages.data.1.status', 'cancelled'));

        $this->actingAs($this->user)->get('/sms?status=pending')->assertInertia(fn (AssertableInertia $page) => $page->where('messages.total', 1));
        $this->actingAs($this->user)->get('/sms?search=Mazur')->assertInertia(fn (AssertableInertia $page) => $page->where('messages.total', 1)->where('messages.data.0.status', 'cancelled'));
        $this->actingAs($this->user)->get('/sms?search=Wizja')->assertInertia(fn (AssertableInertia $page) => $page->where('messages.data.0.client', 'Jan Kowalczyk'), 'a scheduled message is found by its template text');
        $this->actingAs($this->user)->get("/sms?client={$other->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('messages.total', 1));

        $intruder = User::factory()->create(['account_id' => Account::factory()->create()->id]);
        $this->actingAs($intruder)->get('/sms')->assertInertia(fn (AssertableInertia $page) => $page->where('messages.total', 0));
    }

    public function test_klienci_shows_the_overview_columns_and_filters_by_assignee_and_choice_with_counts(): void
    {
        $writer = app(ClientFieldWriter::class);
        $writer->set($this->client, ['visit_status' => 'booked', 'visit_at' => '2026-10-16 10:00']);
        $writer->assign($this->client, $this->user);
        $other = Client::factory()->create(['account_id' => $this->client->account_id, 'name' => 'Piotr Mazur']);

        $this->actingAs($this->user)->get('/clients')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('card.columns', [['key' => 'visit_status', 'label' => 'Wizja'], ['key' => 'visit_at', 'label' => 'Termin wizji']])
                ->where('card.assignees.0', ['value' => 'me', 'label' => null, 'count' => 1])
                ->where('card.facets.0.options.1', ['value' => 'booked', 'label' => 'Umówiona', 'count' => 1])
                ->where('open_months.count', 0)
                ->has('clients.data', 2));

        $this->actingAs($this->user)->get('/clients?assignee=me&f[visit_status]=booked')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('clients.data', 1)
                ->where('clients.data.0.fields.visit_at', '16.10.2026, 10:00')
                ->where('clients.data.0.assignee', 'Marta Lis')
                ->where('card.filters.assignee', 'me'));

        $this->actingAs($this->user)->get('/clients?assignee=none')->assertInertia(fn (AssertableInertia $page) => $page->has('clients.data', 1)->where('clients.data.0.id', $other->id));
        $this->actingAs($this->user)->get('/clients?f[visit_status]=nope&f[note]=x')->assertInertia(fn (AssertableInertia $page) => $page->has('clients.data', 2), 'unknown options and non-choice fields are ignored');
    }

    public function test_the_queue_pages_past_what_waits_without_losing_any(): void
    {
        $writer = app(ClientFieldWriter::class);
        foreach (range(1, 30) as $day) {
            $client = Client::factory()->create(['account_id' => $this->client->account_id, 'phone' => '100100200']);
            $writer->set($client, ['visit_at' => CarbonImmutable::parse('2026-10-14 10:00', 'Europe/Warsaw')->addDays($day)->format('Y-m-d H:i')]);
        }

        $first = $this->actingAs($this->user)->get('/sms')->viewData('page')['props']['messages'];
        $second = $this->actingAs($this->user)->get('/sms?page=2')->viewData('page')['props']['messages'];

        $this->assertSame(30, $first['total']);
        $this->assertCount(30, array_unique([...array_column($first['data'], 'id'), ...array_column($second['data'], 'id')]));
        $this->assertLessThan($second['data'][0]['day'], $first['data'][0]['day'], 'soonest first across pages');
    }

    public function test_an_unknown_assignee_is_refused_and_the_old_one_stays(): void
    {
        app(ClientFieldWriter::class)->assign($this->client, $this->user);
        $stranger = User::factory()->create(['account_id' => Account::factory()->create()->id]);

        $this->actingAs($this->user)->patch("/clients/{$this->client->id}/fields", ['assignee_id' => $stranger->id, 'sms_opt_out' => true])->assertSessionHasErrors('assignee_id');

        $this->client->refresh();
        $this->assertSame([$this->user->id, false], [$this->client->assigned_user_id, $this->client->sms_opt_out], 'nothing of the save is kept');
    }

    public function test_an_account_that_bills_keeps_its_hours_on_przeglad_and_klienci_even_with_fields(): void
    {
        $this->client->account->update(['settings' => ['features' => ['billing' => true]]]);

        $this->actingAs($this->user)->get("/clients/{$this->client->id}/przeglad")->assertInertia(fn (AssertableInertia $page) => $page->where('overview.card', null)->where('overview.sms', null));
        $this->actingAs($this->user)->get('/clients')->assertInertia(fn (AssertableInertia $page) => $page->where('card', null));
        $this->actingAs($this->user)->get("/clients/{$this->client->id}/dane")->assertInertia(fn (AssertableInertia $page) => $page->whereNot('card', null));
    }

    public function test_an_account_without_fields_keeps_the_plain_list(): void
    {
        $plain = User::factory()->create(['account_id' => Account::factory()->create()->id]);

        $this->actingAs($plain)->get('/clients')->assertInertia(fn (AssertableInertia $page) => $page->where('card', null));
    }

    private function failedReminder(string $error): OutboundMessage
    {
        app(ClientFieldWriter::class)->set($this->client, ['visit_at' => '2026-10-16 10:00']);
        $message = OutboundMessage::query()->sole();
        OutboundMessage::query()->whereKey($message->id)->update(['status' => OutboundMessage::FAILED, 'error' => $error, 'to' => '+48100100200', 'body' => 'Wizja 16.10.2026, 10:00.']);

        return $message->fresh();
    }
}
