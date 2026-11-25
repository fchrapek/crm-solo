<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Tools\IntakeFormCreateTool;
use App\Mcp\Tools\IntakeFormEditTool;
use App\Mcp\Tools\IntakeFormsTool;
use App\Mcp\Tools\LeadIntakeRetryTool;
use App\Mcp\Tools\MailSenderTool;
use App\Models\Account;
use App\Models\AgentAuditEvent;
use App\Models\IntakeForm;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadIntake;
use App\Models\User;
use App\Services\Agent\AgentAbilities;
use App\Services\Messaging\Sender\CheckedHost;
use App\Services\Messaging\Sender\HostResolver;
use App\Services\Messaging\Sender\SenderConfig;
use App\Services\Messaging\Sender\SenderTransportFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Tests\Feature\Concerns\CallsAgentSurfaces;
use Tests\TestCase;

/**
 * Agents read and change a website form's non-secret settings, read the mail
 * sender and retry lead notices, inside the acting account, behind
 * write:intake; no verb or tool takes or returns a secret.
 */
final class AgentIntakeTest extends TestCase
{
    use CallsAgentSurfaces;
    use RefreshDatabase;

    private const string TURNSTILE = 'stored-turnstile-value';

    private const string PASSWORD = 'stored-smtp-value';

    private Account $account;

    private User $owner;

    private IntakeForm $form;

    private IntakeForm $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.demo' => false]);
        $this->account = Account::factory()->create();
        $this->owner = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        config(['agent.local_account' => $this->account->id]);
        $this->form = $this->makeForm($this->account, 'Contact drawer');
        $this->foreign = $this->makeForm(Account::factory()->create(), 'Foreign form');
    }

    /** @return array<string, array{string, class-string, array<string, mixed>}> */
    public static function surfaces(): array
    {
        return [
            'forms' => ['crm:intake-forms', IntakeFormsTool::class, []],
            'create' => ['crm:intake-form-create', IntakeFormCreateTool::class, ['name' => 'X', 'pipeline' => 'filipchrapek', 'source' => 'www-form', 'origins' => ['https://www.example.test']]],
            'sender' => ['crm:mail-sender', MailSenderTool::class, []],
            'retry' => ['crm:lead-intake-retry', LeadIntakeRetryTool::class, []],
        ];
    }

    public function test_forms_are_listed_for_the_acting_account_with_secrets_only_as_flags(): void
    {
        $this->form->forceFill(['webhook_secret_hash' => hash('sha256', 'hook')])->save();
        LeadIntake::create(['account_id' => $this->account->id, 'intake_form_id' => $this->form->id, 'idempotency_key' => 'a', 'fingerprint' => str_repeat('0', 64), 'notice_state' => LeadIntake::FAILED]);

        [$exit, $verb] = $this->verb('crm:intake-forms');
        [$ok, $tool] = $this->tool(IntakeFormsTool::class);

        $this->assertSame([0, true], [$exit, $ok]);
        $this->assertSame($verb, $tool);
        $this->assertSame(['Contact drawer'], array_column($verb['forms'], 'name'));
        $form = $verb['forms'][0];
        $this->assertSame([true, true, 1, ['https://www.example.test']], [$form['turnstile_secret_set'], $form['webhook_secret_set'], $form['notices']['failed'], $form['origins']]);
        $this->assertStringEndsWith("/intake/{$this->form->public_key}", $form['endpoint']);
        $json = (string) json_encode($verb);
        $this->assertStringNotContainsString(self::TURNSTILE, $json);
        $this->assertStringNotContainsString(hash('sha256', 'hook'), $json);
    }

    public function test_a_form_is_created_and_edited_with_non_secret_settings_only(): void
    {
        [$exit, $created] = $this->verb('crm:intake-form-create', ['--name' => 'Footer form', '--pipeline' => 'filipchrapek', '--source' => 'www-form', '--origin' => ['https://shop.example.test'], '--notify-to' => 'hi@example.test']);
        $this->assertSame(0, $exit);
        $id = $created['form']['id'];
        $this->assertSame([$this->account->id, false], [IntakeForm::query()->findOrFail($id)->account_id, $created['form']['turnstile_secret_set']]);

        [$ok, $edited] = $this->tool(IntakeFormEditTool::class, ['form' => $id, 'active' => false, 'origins' => ['https://www.example.test'], 'turnstile_secret' => 'smuggled']);

        $this->assertTrue($ok);
        $this->assertSame([false, ['https://www.example.test']], [$edited['form']['active'], $edited['form']['origins']]);
        $this->assertNull(IntakeForm::query()->findOrFail($id)->turnstile_secret);
    }

    public function test_another_accounts_form_is_never_changed(): void
    {
        [$exit, $verb] = $this->verb('crm:intake-form-edit', ['form' => (string) $this->foreign->id, '--active' => 'no']);
        [$ok, $tool] = $this->tool(IntakeFormEditTool::class, ['form' => $this->foreign->id, 'name' => 'Taken']);

        $this->assertSame([1, false, 'invalid_argument', 'invalid_argument'], [$exit, $ok, $verb['error'], $tool['error']]);
        $this->foreign->refresh();
        $this->assertSame([true, 'Foreign form'], [$this->foreign->is_active, $this->foreign->name]);
    }

    public function test_bad_settings_are_refused_with_the_fields_named(): void
    {
        [$exit, $verb] = $this->verb('crm:intake-form-create', ['--name' => 'X', '--pipeline' => 'nope', '--source' => 'www-form', '--origin' => ['http://www.example.test']]);
        [$ok, $tool] = $this->tool(IntakeFormCreateTool::class, ['name' => 'X', 'pipeline' => 'filipchrapek', 'source' => 'nope', 'origins' => ['https://www.example.test']]);

        $this->assertSame([1, ['pipeline', 'origins']], [$exit, $verb['fields']]);
        $this->assertSame([false, ['source']], [$ok, $tool['fields']]);
        $this->assertSame(2, IntakeForm::query()->count());
    }

    public function test_no_verb_or_tool_takes_a_secret(): void
    {
        foreach (['crm:intake-forms', 'crm:intake-form-create', 'crm:intake-form-edit', 'crm:mail-sender', 'crm:lead-intake-retry'] as $command) {
            $options = array_keys(Artisan::all()[$command]->getDefinition()->getOptions());
            $this->assertSame([], array_values(array_filter($options, fn (string $name): bool => preg_match('/secret|pass|token|key/', $name) === 1)), $command);
        }
        foreach ([IntakeFormsTool::class, IntakeFormCreateTool::class, IntakeFormEditTool::class, MailSenderTool::class, LeadIntakeRetryTool::class] as $tool) {
            $properties = array_keys((array) (app($tool)->toArray()['inputSchema']['properties'] ?? []));
            $this->assertSame([], array_values(array_filter($properties, fn (string $name): bool => preg_match('/secret|pass|token|key/', $name) === 1)), $tool);
        }
        $this->assertSame(['form', 'name', 'pipeline', 'source', 'origins', 'notify_to', 'active'], array_keys((array) app(IntakeFormEditTool::class)->toArray()['inputSchema']['properties']));
    }

    public function test_the_mail_sender_reads_without_its_password_and_with_the_last_test(): void
    {
        [, $none] = $this->verb('crm:mail-sender');
        $this->assertSame(['configured' => false], $none['sender']);

        $this->sender();
        $this->fakeTransport();
        $this->assertSame(0, Artisan::call('mail-sender:test', ['--account' => $this->account->id]));

        [$exit, $verb] = $this->verb('crm:mail-sender');
        [$ok, $tool] = $this->tool(MailSenderTool::class);

        $this->assertSame([0, true], [$exit, $ok]);
        $this->assertSame($verb, $tool);
        $sender = $verb['sender'];
        $this->assertSame(['smtp.example.test', 587, 'box@example.test', true, true, true], [$sender['host'], $sender['port'], $sender['username'], $sender['enabled'], $sender['password_set'], $sender['last_test']['ok']]);
        $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode($verb));
    }

    public function test_a_failed_test_send_is_recorded_as_a_reason_code(): void
    {
        $this->sender();
        $this->app->instance(HostResolver::class, new class implements HostResolver
        {
            public function addresses(string $host): array
            {
                return [];
            }
        });

        Artisan::call('mail-sender:test', ['--account' => $this->account->id]);

        [, $verb] = $this->verb('crm:mail-sender');
        $this->assertSame([false, 'unresolved'], [$verb['sender']['last_test']['ok'], $verb['sender']['last_test']['reason']]);
    }

    public function test_retry_sends_the_acting_accounts_failed_notices_only(): void
    {
        Queue::fake();
        $this->sender();
        Integration::create(['account_id' => $this->foreign->account_id, 'provider' => Integration::SMTP, 'is_enabled' => true, 'api_key' => 'x', 'settings' => ['host' => 'smtp.example.test']]);
        $mine = $this->failedNotice($this->form);
        $theirs = $this->failedNotice($this->foreign);

        [$exit, $result] = $this->verb('crm:lead-intake-retry');

        $this->assertSame([0, 1, 1], [$exit, $result['retried'], $result['queued']]);
        $this->assertSame([LeadIntake::WAITING, LeadIntake::FAILED], [$mine->refresh()->notice_state, $theirs->refresh()->notice_state]);
        [$ok, $again] = $this->tool(LeadIntakeRetryTool::class);
        $this->assertSame([true, 0], [$ok, $again['retried']]);
    }

    /** @param  array<string, mixed>  $toolArguments */
    #[DataProvider('surfaces')]
    public function test_every_verb_and_tool_refuses_in_the_demo(string $command, string $tool, array $toolArguments): void
    {
        config(['app.demo' => true]);

        [$exit, $verb] = $this->verb($command, $command === 'crm:intake-form-create' ? ['--name' => 'X', '--pipeline' => 'filipchrapek', '--source' => 'www-form', '--origin' => ['https://www.example.test']] : []);
        [$ok, $payload] = $this->tool($tool, $toolArguments);

        $this->assertSame([1, false, 'refused', 'refused'], [$exit, $ok, $verb['error'], $payload['error']]);
        $this->assertSame(2, IntakeForm::query()->count());
    }

    public function test_a_read_token_reads_and_only_write_intake_changes_forms_with_an_audit_row(): void
    {
        $read = $this->token([AgentAbilities::READ, AgentAbilities::LEADS]);

        $this->remote($read, 'intake-forms', ['--json'])->assertOk()->assertHeader('X-Crm-Exit', '0')->assertSee('Contact drawer');
        $this->remote($read, 'mail-sender', ['--json'])->assertOk()->assertHeader('X-Crm-Exit', '0');
        $this->remote($read, 'intake-form-edit', [(string) $this->form->id, '--active=no'])->assertForbidden()->assertSee('write:intake');
        $this->remote($read, 'intake-form-create', ['--name=X'])->assertForbidden()->assertSee('write:intake');
        $this->remote($read, 'lead-intake-retry')->assertForbidden()->assertSee('write:intake');
        $tools = collect($this->mcp($read, 'tools/list')->assertOk()->json('result.tools'))->pluck('name');
        $this->assertContains('intake_forms', $tools);
        $this->assertContains('mail_sender', $tools);
        $this->assertNotContains('intake_form_edit', $tools);
        $this->assertTrue($this->form->fresh()->is_active);

        $write = $this->token([AgentAbilities::READ, AgentAbilities::INTAKE]);
        $this->remote($write, 'intake-form-edit', [(string) $this->form->id, '--active=no', '--json'])->assertOk()->assertHeader('X-Crm-Exit', '0');

        $this->assertFalse($this->form->fresh()->is_active);
        $audit = AgentAuditEvent::query()->where('target_type', 'intake_forms')->where('target_id', $this->form->id)->where('action', 'updated')->firstOrFail();
        $this->assertSame(['cli-remote', 'crm:intake-form-edit'], [$audit->via, $audit->verb]);
        $this->assertStringNotContainsString(self::TURNSTILE, (string) json_encode($audit->changes));
    }

    public function test_write_intake_is_granted_only_by_name_never_by_the_write_shorthand(): void
    {
        $this->assertContains(AgentAbilities::INTAKE, AgentAbilities::ALL);
        $this->assertNotContains(AgentAbilities::INTAKE, AgentAbilities::normalise(['write']));
        $this->assertContains(AgentAbilities::TRELLO, AgentAbilities::normalise(['write']));
        $this->assertContains(AgentAbilities::INTAKE, AgentAbilities::normalise(['write', 'write:intake']));
    }

    public function test_a_settings_change_clears_the_last_test_result(): void
    {
        $this->sender();
        $this->app->instance(HostResolver::class, new class implements HostResolver
        {
            public function addresses(string $host): array
            {
                return ['104.16.0.1'];
            }
        });
        $senders = app(\App\Services\Messaging\Sender\AccountSenders::class);
        $senders->recordTest($this->account->id, 'transport');
        $this->assertFalse($senders->describe($this->account->id)['last_test']['ok']);

        $senders->save($this->account->id, ['host' => 'smtp.example.test', 'port' => 465, 'username' => 'box@example.test', 'from_address' => 'box@example.test', 'from_name' => null], null);

        $this->assertNull($senders->describe($this->account->id)['last_test']);
    }

    public function test_an_edit_with_nothing_to_change_names_every_field_that_can_change(): void
    {
        [$exit, $verb] = $this->verb('crm:intake-form-edit', ['form' => (string) $this->form->id]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--pipeline, --source', $verb['message']);
    }

    public function test_a_notify_to_address_the_mailer_would_refuse_is_refused(): void
    {
        [$exit, $verb] = $this->verb('crm:intake-form-edit', ['form' => (string) $this->form->id, '--notify-to' => 'hi(comment)@example.test']);
        $this->assertSame(1, Artisan::call('intake-form:set', ['--account' => $this->account->id, '--form' => $this->form->id, '--notify-to' => 'hi(comment)@example.test']));

        $this->assertSame([1, ['notify_to']], [$exit, $verb['fields']]);
        $this->assertSame('hi@example.test', $this->form->fresh()->notify_to);
    }

    public function test_a_notice_sent_between_the_retrys_read_and_its_update_stays_sent_and_unrecorded(): void
    {
        Queue::fake();
        $this->sender();
        $notice = $this->failedNotice($this->form);
        $delivered = false;
        DB::listen(function (QueryExecuted $query) use (&$delivered, $notice): void {
            $readsFailed = str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "lead_intakes"') && in_array(LeadIntake::FAILED, $query->bindings, true);
            if (! $delivered && $readsFailed) {
                $delivered = true;
                DB::table('lead_intakes')->where('id', $notice->id)->update(['notice_state' => LeadIntake::SENT]);
            }
        });

        [$exit, $result] = $this->verb('crm:lead-intake-retry');

        $this->assertTrue($delivered, 'the worker delivered between the read and the update');
        $this->assertSame([0, 0], [$exit, $result['retried']]);
        $this->assertSame(LeadIntake::SENT, $notice->fresh()->notice_state);
        $this->assertSame(0, AgentAuditEvent::query()->where('target_type', 'lead_intakes')->where('target_id', $notice->id)->count());
    }

    public function test_intake_writes_land_in_the_agent_write_record_without_secrets(): void
    {
        Queue::fake();
        [, $created] = $this->verb('crm:intake-form-create', ['--name' => 'Footer form', '--pipeline' => 'filipchrapek', '--source' => 'www-form', '--origin' => ['https://shop.example.test']]);
        $this->sender();
        $notice = $this->failedNotice($this->form);
        $this->verb('crm:lead-intake-retry');

        $create = AgentAuditEvent::query()->where('target_type', 'intake_forms')->where('target_id', $created['form']['id'])->where('action', 'created')->sole();
        $retry = AgentAuditEvent::query()->where('target_type', 'lead_intakes')->where('target_id', $notice->id)->where('action', 'updated')->sole();
        $this->assertSame(['crm:intake-form-create', 'crm:lead-intake-retry'], [$create->verb, $retry->verb]);
        $this->assertSame(LeadIntake::FAILED, $retry->changes['before']['notice_state']);

        $this->form->forceFill(['webhook_secret_hash' => hash('sha256', 'hook')])->save();
        $this->verb('crm:intake-form-edit', ['form' => (string) $this->form->id, '--name' => 'Renamed', '--active' => 'no']);
        $edit = AgentAuditEvent::query()->where('target_type', 'intake_forms')->where('target_id', $this->form->id)->where('action', 'updated')->sole();
        $trail = (string) json_encode([$create->changes, $edit->changes, $retry->changes]);
        $this->assertSame('Contact drawer', $edit->changes['before']['name']);
        foreach (['turnstile_secret', 'webhook_secret_hash', self::TURNSTILE, hash('sha256', 'hook')] as $secret) {
            $this->assertStringNotContainsString($secret, $trail);
        }
    }

    private function failedNotice(IntakeForm $form): LeadIntake
    {
        $lead = Lead::create(['account_id' => $form->account_id, 'pipeline' => 'filipchrapek', 'source' => 'www-form', 'name' => 'Ewa', 'email' => 'ewa@example.test']);
        $intake = LeadIntake::create([
            'account_id' => $form->account_id, 'intake_form_id' => $form->id, 'lead_id' => $lead->id,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'fingerprint' => str_repeat('0', 64), 'notice_state' => LeadIntake::FAILED,
        ]);
        $intake->forceFill(['created_at' => now()->subHour()])->save();

        return $intake;
    }

    private function sender(): void
    {
        Integration::create([
            'account_id' => $this->account->id,
            'provider' => Integration::SMTP,
            'is_enabled' => true,
            'api_key' => self::PASSWORD,
            'settings' => ['host' => 'smtp.example.test', 'port' => 587, 'username' => 'box@example.test', 'from_address' => 'box@example.test', 'from_name' => 'Studio'],
        ]);
    }

    private function fakeTransport(): void
    {
        $this->app->instance(HostResolver::class, new class implements HostResolver
        {
            public function addresses(string $host): array
            {
                return ['104.16.0.1'];
            }
        });
        $this->app->instance(SenderTransportFactory::class, new class implements SenderTransportFactory
        {
            public function make(SenderConfig $sender, CheckedHost $host): TransportInterface
            {
                return new ArrayTransport;
            }
        });
    }

    private function makeForm(Account $account, string $name): IntakeForm
    {
        return IntakeForm::create([
            'account_id' => $account->id,
            'name' => $name,
            'public_key' => IntakeForm::newPublicKey(),
            'allowed_origins' => ['https://www.example.test'],
            'pipeline' => 'filipchrapek',
            'source' => 'www-form',
            'notify_to' => 'hi@example.test',
            'turnstile_secret' => self::TURNSTILE,
            'is_active' => true,
        ]);
    }

    /** @param  list<string>  $abilities */
    private function token(array $abilities): string
    {
        $issued = $this->owner->createToken('agent', $abilities, now()->addDays(30));
        $issued->accessToken->forceFill(['account_id' => $this->owner->account_id])->save();

        return $issued->plainTextToken;
    }

    /** @param  list<string>  $args */
    private function remote(string $token, string $verb, array $args = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->post('/agent/verb', ['verb' => $verb, 'args' => $args]);
    }

    /** @param  array<string, mixed>  $params */
    private function mcp(string $token, string $method, array $params = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
    }
}
