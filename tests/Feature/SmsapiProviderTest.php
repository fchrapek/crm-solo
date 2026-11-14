<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\CrmMessageSending;
use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\MessageCancelTool;
use App\Mcp\Tools\MessageSendingTool;
use App\Mcp\Tools\MessageTemplateSetTool;
use App\Models\Account;
use App\Models\AgentAuditEvent;
use App\Models\Client;
use App\Models\Integration;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Agent\AgentRefusal;
use App\Services\Clients\Fields\ClientFieldDefinitions;
use App\Services\Clients\Fields\ClientFieldWriter;
use App\Services\Messaging\MessageDispatcher;
use App\Services\Messaging\MessageSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Text messages through SMSAPI.pl with the account's own token. */
final class SmsapiProviderTest extends TestCase
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
        $settings->rule($this->account->id, 'confirm', ['template' => 'booked', 'trigger' => 'field_value', 'field' => 'visit_at', 'expire_minutes' => 120]);
    }

    public function test_a_message_goes_out_with_the_accounts_token_and_keeps_the_smsapi_id(): void
    {
        $this->connect();
        Http::fake(['api.smsapi.pl/*' => Http::response(['count' => 1, 'list' => [['id' => 'abc123', 'status' => 'QUEUE']]])]);

        $this->book();

        $this->assertSame([OutboundMessage::SENT => 1], app(MessageDispatcher::class)->dispatchDue());
        $message = OutboundMessage::query()->sole();
        $this->assertSame(['smsapi', 'abc123', '+48100100200'], [$message->provider, $message->provider_message_id, $message->to]);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer token-of-the-account')
            && $request['to'] === '48100100200'
            && $request['idx'] === (string) $message->id
            && $request['encoding'] === 'utf-8'
            && ! isset($request['test']));
    }

    public function test_an_smsapi_refusal_fails_the_message_with_its_code_and_it_is_not_resent(): void
    {
        $this->connect();
        Http::fake(['api.smsapi.pl/*' => Http::response(['error' => 103, 'message' => 'Not enough points'], 400)]);

        $this->book();

        $this->assertSame([OutboundMessage::FAILED => 1], app(MessageDispatcher::class)->dispatchDue());
        $this->assertSame([], app(MessageDispatcher::class)->dispatchDue());
        $this->assertSame('SMSAPI 103: Not enough points', OutboundMessage::query()->sole()->error);
        Http::assertSentCount(1);
    }

    public function test_no_answer_fails_the_message_and_says_it_may_have_gone_out(): void
    {
        $this->connect();
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->book();

        $this->assertSame([OutboundMessage::FAILED => 1], app(MessageDispatcher::class)->dispatchDue());
        $this->assertStringStartsWith('no answer from SMSAPI, the message may have gone out', (string) OutboundMessage::query()->sole()->error);
    }

    public function test_test_mode_asks_smsapi_to_check_the_message_and_records_it_as_simulated(): void
    {
        config(['services.smsapi.test' => true]);
        $this->connect();
        Http::fake(['api.smsapi.pl/*' => Http::response(['count' => 1, 'list' => [['id' => 'test-1', 'status' => 'QUEUE']]])]);

        $this->book();

        $this->assertSame([OutboundMessage::SIMULATED => 1], app(MessageDispatcher::class)->dispatchDue());
        Http::assertSent(fn (Request $request): bool => $request['test'] === '1');
    }

    public function test_smsapi_cannot_be_chosen_before_it_is_connected_and_a_later_disconnect_fails_instead_of_sending(): void
    {
        $refusal = function (string $provider): string {
            try {
                app(MessageSettings::class)->sending($this->account->id, provider: $provider);
            } catch (AgentRefusal $e) {
                return $e->getMessage();
            }

            return '';
        };
        $this->assertStringContainsString('Connect SMSAPI', $refusal('smsapi'));
        $this->assertStringContainsString('Unknown SMS provider', $refusal('twilio'));
        $this->assertSame('simulator', app(MessageSettings::class)->sendingOf($this->account->fresh())['provider']);

        $this->connect();
        Integration::query()->where('provider', 'smsapi')->update(['is_enabled' => false]);
        Http::fake();

        $this->book();

        $this->assertSame([OutboundMessage::FAILED => 1], app(MessageDispatcher::class)->dispatchDue());
        $this->assertSame('SMSAPI is not connected in Integrations', OutboundMessage::query()->sole()->error);
        Http::assertNothingSent();
    }

    public function test_an_agent_cannot_switch_the_provider_through_the_verb_or_the_tool(): void
    {
        Integration::create(['account_id' => $this->account->id, 'provider' => 'smsapi', 'is_enabled' => true, 'api_key' => 'token-of-the-account']);

        $this->assertFalse(app(CrmMessageSending::class)->getDefinition()->hasOption('provider'));
        CrmServer::tool(MessageSendingTool::class, ['provider' => 'smsapi', 'quiet_from' => '21:00'])->assertOk();

        $sending = app(MessageSettings::class)->sendingOf($this->account->fresh());
        $this->assertSame(['simulator', '21:00'], [$sending['provider'], $sending['quiet_from']]);
    }

    public function test_real_messages_stop_at_the_accounts_daily_cap_and_the_simulator_is_never_capped(): void
    {
        config(['services.smsapi.daily_cap' => 1]);
        $this->connect();
        Http::fake(['api.smsapi.pl/*' => Http::response(['count' => 1, 'list' => [['id' => 'abc123', 'status' => 'QUEUE']]])]);
        $second = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Nowak Dom', 'phone' => '100 100 300']);

        $this->book();
        $this->assertSame([OutboundMessage::SENT => 1], app(MessageDispatcher::class)->dispatchDue());
        app(ClientFieldWriter::class)->set($second, ['visit_at' => '2026-10-21 10:00']);
        $this->assertSame([OutboundMessage::FAILED => 1], app(MessageDispatcher::class)->dispatchDue());

        $this->assertStringStartsWith('daily_cap', (string) OutboundMessage::query()->where('client_id', $second->id)->sole()->error);
        Http::assertSentCount(1);

        app(MessageSettings::class)->sending($this->account->id, provider: 'simulator');
        app(MessageSettings::class)->resend($this->account->id, OutboundMessage::query()->where('client_id', $second->id)->sole()->id);
        $this->assertSame([OutboundMessage::SIMULATED => 1], app(MessageDispatcher::class)->dispatchDue());
    }

    public function test_an_agents_template_write_and_cancel_leave_rows_in_the_agent_trail(): void
    {
        CrmServer::tool(MessageTemplateSetTool::class, ['key' => 'late', 'name' => 'Spóźnienie', 'body' => 'Będziemy później.'])->assertOk();
        $this->book();
        CrmServer::tool(MessageCancelTool::class, ['id' => OutboundMessage::query()->sole()->id])->assertOk();

        $this->assertSame(['message_templates', 'outbound_messages'], AgentAuditEvent::query()->orderBy('id')->pluck('target_type')->unique()->values()->all());
    }

    public function test_settings_switch_the_account_to_smsapi_only_once_it_is_connected(): void
    {
        $owner = User::query()->where('account_id', $this->account->id)->sole();

        $this->actingAs($owner)->get('/settings/sms')->assertInertia(fn (AssertableInertia $page) => $page->where('sending.provider', 'simulator')->where('sending.smsapi_connected', false));
        $this->actingAs($owner)->patch('/settings/sms/sending', ['provider' => 'smsapi'])->assertSessionHasErrors('provider');
        $this->assertSame('simulator', app(MessageSettings::class)->sendingOf($this->account->fresh())['provider']);

        Integration::create(['account_id' => $this->account->id, 'provider' => 'smsapi', 'is_enabled' => true, 'api_key' => 'token-of-the-account']);
        $this->actingAs($owner)->patch('/settings/sms/sending', ['provider' => 'smsapi'])->assertSessionHasNoErrors();

        $this->actingAs($owner->fresh())->get('/settings/sms')->assertInertia(fn (AssertableInertia $page) => $page->where('sending.provider', 'smsapi')->where('sending.smsapi_connected', true));
        $this->actingAs($owner)->patch('/settings/sms/sending', ['provider' => 'carrier-pigeon'])->assertSessionHasErrors('provider');
    }

    public function test_the_integrations_page_lists_smsapi_without_a_sync(): void
    {
        $owner = User::query()->where('account_id', $this->account->id)->sole();

        $this->actingAs($owner)->get('/settings/integracje')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('integrations.1.provider', 'trello')->where('integrations.1.syncs', true)
            ->where('integrations.2.provider', 'smsapi')->where('integrations.2.syncs', false));
    }

    private function connect(): void
    {
        Integration::create(['account_id' => $this->account->id, 'provider' => 'smsapi', 'is_enabled' => true, 'api_key' => 'token-of-the-account']);
        app(MessageSettings::class)->sending($this->account->id, provider: 'smsapi');
    }

    private function book(): void
    {
        app(ClientFieldWriter::class)->set($this->client, ['visit_at' => '2026-10-20 10:00']);
    }
}
