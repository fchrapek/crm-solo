<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\CrmServer;
use App\Models\Account;
use App\Models\AgentAuditEvent;
use App\Models\Client;
use App\Models\ClientSummaryEmail;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Agent\AgentAbilities;
use App\Services\Messaging\MessageSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use ReflectionClass;
use Tests\TestCase;

/**
 * An agent makes or refreshes a summary email draft, the verb and the tool
 * alike, under write:reports. Nothing on the agent surface sends one.
 */
final class AgentSummaryEmailDraftTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Client $client;

    private string $plain;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Carbon::setTestNow('2026-11-03 10:00:00');
        $account = Account::factory()->create();
        $this->owner = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $this->client = Client::factory()->create(['account_id' => $account->id, 'name' => 'Brzozowy Sad', 'email' => 'biuro@brzozowy-sad.test', 'month_close_type' => 'gig', 'report_mode' => 'summary_email']);
        app(MessageSettings::class)->template($account->id, 'monthly', 'Miesięczne', 'Notka za {period}.', MessageTemplate::EMAIL, 'Podsumowanie {client_name}');
        $this->plain = $this->token([AgentAbilities::READ, AgentAbilities::REPORTS]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_verb_makes_the_draft_and_is_audited(): void
    {
        $json = $this->verb('summary-email-draft', ['Brzozowy Sad', '--month=2026-10', '--json'])->assertOk()->assertHeader('X-Crm-Exit', '0')->json();

        $this->assertSame(['draft', '2026-10', 'Podsumowanie Brzozowy Sad', 'Notka za październik 2026.'], [$json['email']['status'], $json['email']['period'], $json['email']['subject'], mb_trim($json['email']['body'])]);
        $this->assertSame(['subject', 'body'], $json['email']['untrusted']);
        $this->assertSame('agent', $json['email']['drafted_via']);
        $this->assertSame(['biuro@brzozowy-sad.test'], array_column($json['email']['candidates'], 'email'));
        $this->assertSame('crm:summary-email-draft', AgentAuditEvent::query()->where('target_type', 'client_summary_emails')->sole()->verb);
        Mail::assertNothingOutgoing();
    }

    public function test_the_tool_refreshes_the_same_draft(): void
    {
        $this->verb('summary-email-draft', ['Brzozowy Sad', '--month=2026-10', '--json'])->assertOk();

        $this->mcp('tools/call', ['name' => 'summary_email_draft', 'arguments' => ['client' => 'Brzozowy Sad', 'month' => '2026-10']])
            ->assertOk()->assertJsonPath('result.isError', false);

        $this->assertSame(1, ClientSummaryEmail::query()->count());
        Mail::assertNothingOutgoing();
    }

    public function test_the_draft_needs_write_reports(): void
    {
        $this->plain = $this->token([AgentAbilities::READ, AgentAbilities::MONTH_CLOSE]);

        $this->verb('summary-email-draft', ['Brzozowy Sad', '--month=2026-10'])->assertForbidden()->assertSee('write:reports');
        $this->assertSame(0, ClientSummaryEmail::query()->count());
    }

    public function test_a_sent_email_is_refused_as_not_a_draft(): void
    {
        $this->verb('summary-email-draft', ['Brzozowy Sad', '--month=2026-10', '--json'])->assertOk();
        ClientSummaryEmail::query()->update(['status' => ClientSummaryEmail::SENT]);

        $this->verb('summary-email-draft', ['Brzozowy Sad', '--month=2026-10', '--json'])->assertHeader('X-Crm-Exit', '1')->assertSee('not_a_draft');
    }

    public function test_an_agent_refresh_leaves_a_text_the_owner_edited(): void
    {
        $this->verb('summary-email-draft', ['Brzozowy Sad', '--month=2026-10', '--json'])->assertOk();
        ClientSummaryEmail::query()->update(['subject' => 'Ręczny', 'text_edited_at' => now()]);

        $this->verb('summary-email-draft', ['Brzozowy Sad', '--month=2026-10', '--json'])->assertHeader('X-Crm-Exit', '1')->assertSee('edited by hand');

        $this->assertSame('Ręczny', ClientSummaryEmail::query()->sole()->subject);
    }

    public function test_no_verb_or_tool_sends_a_summary_email(): void
    {
        $names = [...array_keys(AgentAbilities::VERBS), ...array_keys(AgentAbilities::COMMANDS), ...array_keys(AgentAbilities::TOOLS)];
        foreach ((new ReflectionClass(CrmServer::class))->getDefaultProperties()['tools'] as $tool) {
            $names[] = app($tool)->name();
        }
        $emailNames = array_values(array_filter(array_unique($names), fn (string $name): bool => str_contains($name, 'email')));
        sort($emailNames);
        $this->assertSame(['crm:summary-email-draft', 'summary-email-draft', 'summary_email_draft'], $emailNames);

        foreach ([...File::allFiles(app_path('Console')), ...File::allFiles(app_path('Mcp'))] as $file) {
            $source = $file->getContents();
            foreach (['SendSummaryEmail', 'ClientSummaryMail', '->markSent(', 'emails->send(', 'tickStep('] as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$file->getFilename()} must not send a summary email");
            }
        }
    }

    /** @param  list<string>  $abilities */
    private function token(array $abilities): string
    {
        $issued = $this->owner->createToken('agent', $abilities, now()->addDays(30));
        $issued->accessToken->forceFill(['account_id' => $this->owner->account_id])->save();

        return $issued->plainTextToken;
    }

    /** @param  list<string>  $args */
    private function verb(string $verb, array $args = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->plain])->post('/agent/verb', ['verb' => $verb, 'args' => $args]);
    }

    /** @param  array<string, mixed>  $params */
    private function mcp(string $method, array $params = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->plain])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
    }
}
