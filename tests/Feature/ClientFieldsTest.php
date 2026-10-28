<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\ClientAssignTool;
use App\Mcp\Tools\ClientFieldDefineTool;
use App\Mcp\Tools\ClientFieldSetTool;
use App\Mcp\Tools\ClientFieldsTool;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientFieldEvent;
use App\Models\User;
use App\Services\Agent\AgentRefusal;
use App\Services\Clients\Fields\ClientFieldDefinitions;
use App\Services\Clients\Fields\ClientFieldWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** Fields an account adds to its clients: defined per account, written through one path, every change kept. */
final class ClientFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.display_timezone' => 'Europe/Warsaw']);
        $this->account = Account::factory()->create();
        config(['agent.local_account' => $this->account->id]);
        $this->owner = User::factory()->create(['account_id' => $this->account->id, 'owner' => true, 'first_name' => 'Ewa', 'last_name' => 'Owner']);
        $this->client = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Kowalski Dom']);

        $definitions = app(ClientFieldDefinitions::class);
        $definitions->define($this->account->id, 'visit_status', ['label' => 'Wizja', 'type' => 'select', 'options' => [['key' => 'to_book', 'label' => 'Do umówienia'], ['key' => 'booked', 'label' => 'Umówiona']]]);
        $definitions->define($this->account->id, 'visit_at', ['label' => 'Termin wizji', 'type' => 'datetime']);
        $definitions->define($this->account->id, 'scope', ['label' => 'Zakres', 'type' => 'multiselect', 'options' => [['key' => 'walls', 'label' => 'Ściany'], ['key' => 'roof', 'label' => 'Dach'], ['key' => 'windows', 'label' => 'Okna']]]);
    }

    public function test_the_verb_sets_several_fields_and_stores_option_keys_and_utc_times(): void
    {
        Artisan::call('crm:client-field-set', ['client' => 'Kowalski', 'values' => ['visit_status=Umówiona', 'visit_at=2026-10-20 10:00', 'scope=roof,walls'], '--json' => true]);

        $this->client->refresh();
        $this->assertSame('booked', $this->client->custom_fields['visit_status']);
        $this->assertSame('2026-10-20T08:00:00Z', $this->client->custom_fields['visit_at']);
        $this->assertSame(['walls', 'roof'], $this->client->custom_fields['scope']);
        $this->assertSame(3, ClientFieldEvent::query()->where('client_id', $this->client->id)->count());
    }

    public function test_one_bad_value_writes_nothing(): void
    {
        $this->expectException(AgentRefusal::class);

        try {
            app(ClientFieldWriter::class)->set($this->client, ['visit_status' => 'booked', 'visit_at' => 'next tuesday']);
        } finally {
            $this->assertNull($this->client->fresh()->custom_fields);
            $this->assertSame(0, ClientFieldEvent::query()->count());
        }
    }

    public function test_an_unknown_option_is_refused_with_the_valid_ones(): void
    {
        CrmServer::tool(ClientFieldSetTool::class, ['client' => (string) $this->client->id, 'values' => ['visit_status' => 'maybe']])
            ->assertHasErrors()
            ->assertSee('to_book')
            ->assertSee('booked');
    }

    public function test_renaming_an_option_keeps_the_stored_value_and_the_history_keeps_the_old_text(): void
    {
        app(ClientFieldWriter::class)->set($this->client, ['visit_status' => 'booked'], $this->owner);

        CrmServer::tool(ClientFieldDefineTool::class, ['key' => 'visit_status', 'options' => [['key' => 'booked', 'label' => 'Potwierdzona']]])->assertOk();
        Artisan::call('crm:brief', ['client' => (string) $this->client->id, '--json' => true]);
        $fields = collect(json_decode(Artisan::output(), true)['fields'])->keyBy('key');

        $this->assertSame('booked', $fields['visit_status']['value']);
        $this->assertSame('Potwierdzona', $fields['visit_status']['display']);
        $event = ClientFieldEvent::query()->where('field_key', 'visit_status')->sole();
        $this->assertSame('Umówiona', $event->new_display);
        $this->assertSame($this->owner->id, $event->user_id);
    }

    public function test_the_type_of_an_existing_field_never_changes(): void
    {
        $this->expectException(AgentRefusal::class);

        app(ClientFieldDefinitions::class)->define($this->account->id, 'visit_at', ['type' => 'date']);
    }

    public function test_a_required_field_cannot_be_cleared_and_an_archived_one_cannot_be_written(): void
    {
        $definitions = app(ClientFieldDefinitions::class);
        $definitions->define($this->account->id, 'visit_status', ['required' => true]);
        app(ClientFieldWriter::class)->set($this->client, ['visit_status' => 'booked', 'visit_at' => '2026-10-20 10:00']);

        try {
            app(ClientFieldWriter::class)->set($this->client, ['visit_status' => '']);
            $this->fail('Cleared a required field.');
        } catch (AgentRefusal) {
        }

        $definitions->define($this->account->id, 'visit_at', ['archived' => true]);
        try {
            app(ClientFieldWriter::class)->set($this->client, ['visit_at' => '2026-10-21 10:00']);
            $this->fail('Wrote an archived field.');
        } catch (AgentRefusal) {
        }

        $this->assertSame('2026-10-20T08:00:00Z', $this->client->fresh()->custom_fields['visit_at']);
        CrmServer::tool(ClientFieldsTool::class, [])->assertOk()->assertDontSee('visit_at');
    }

    public function test_another_accounts_fields_and_users_do_not_exist_for_the_agent(): void
    {
        $other = Account::factory()->create();
        app(ClientFieldDefinitions::class)->define($other->id, 'secret', ['label' => 'Secret', 'type' => 'text']);
        $stranger = User::factory()->create(['account_id' => $other->id, 'first_name' => 'Obcy']);

        CrmServer::tool(ClientFieldSetTool::class, ['client' => (string) $this->client->id, 'values' => ['secret' => 'x']])->assertHasErrors();
        CrmServer::tool(ClientAssignTool::class, ['client' => (string) $this->client->id, 'user' => (string) $stranger->id])->assertHasErrors();
        CrmServer::tool(ClientFieldsTool::class, [])->assertOk()->assertDontSee('secret');

        $this->assertNull($this->client->fresh()->assigned_user_id);
    }

    public function test_assigning_records_the_change_and_none_clears_it(): void
    {
        $auditor = User::factory()->create(['account_id' => $this->account->id, 'first_name' => 'Łukasz', 'last_name' => 'Audytor']);

        CrmServer::tool(ClientAssignTool::class, ['client' => 'Kowalski', 'user' => 'Audytor'])->assertOk();
        $this->assertSame($auditor->id, $this->client->fresh()->assigned_user_id);

        Artisan::call('crm:client-assign', ['client' => 'Kowalski', 'user' => 'none']);
        $this->assertNull($this->client->fresh()->assigned_user_id);
        $this->assertSame(['Łukasz Audytor', null], ClientFieldEvent::query()->where('field_key', 'assignee')->orderBy('id')->get()->map(fn ($e) => $e->new_display)->all());
    }

    public function test_bad_dates_and_lists_are_refused_not_thrown(): void
    {
        app(ClientFieldDefinitions::class)->define($this->account->id, 'audit_due', ['label' => 'Audyt do', 'type' => 'date']);

        foreach ([['audit_due' => '2026-13-40'], ['audit_due' => '20.10.2026'], ['scope' => [1, 2]]] as $values) {
            CrmServer::tool(ClientFieldSetTool::class, ['client' => (string) $this->client->id, 'values' => $values])->assertHasErrors()->assertSee('invalid_argument');
        }
        $this->assertNull($this->client->fresh()->custom_fields);
    }

    public function test_a_full_name_picks_one_of_two_users_with_the_same_first_name(): void
    {
        User::factory()->create(['account_id' => $this->account->id, 'first_name' => 'Łukasz', 'last_name' => 'Mazur']);
        $krol = User::factory()->create(['account_id' => $this->account->id, 'first_name' => 'Łukasz', 'last_name' => 'Król']);

        CrmServer::tool(ClientAssignTool::class, ['client' => 'Kowalski', 'user' => 'Łukasz'])->assertHasErrors();
        CrmServer::tool(ClientAssignTool::class, ['client' => 'Kowalski', 'user' => 'Łukasz Król'])->assertOk();

        $this->assertSame($krol->id, $this->client->fresh()->assigned_user_id);
    }

    public function test_a_time_that_does_not_exist_as_typed_is_refused(): void
    {
        foreach (['2026-02-30 10:00', '2026-03-29 02:30', '2026-10-20 10:00 +1 day', 'tomorrow 10:00', '2026-10-20T10:00+99:99'] as $typed) {
            CrmServer::tool(ClientFieldSetTool::class, ['client' => (string) $this->client->id, 'values' => ['visit_at' => $typed]])->assertHasErrors()->assertSee('invalid_argument');
        }

        app(ClientFieldWriter::class)->set($this->client, ['visit_at' => '2026-10-20T08:00Z']);
        $this->assertSame('2026-10-20T08:00:00Z', $this->client->fresh()->custom_fields['visit_at']);
    }

    public function test_blank_text_cannot_empty_a_required_field(): void
    {
        $definitions = app(ClientFieldDefinitions::class);
        $definitions->define($this->account->id, 'town', ['label' => 'Miejscowość', 'type' => 'text', 'required' => true]);
        app(ClientFieldWriter::class)->set($this->client, ['town' => 'Istebna']);

        $this->expectException(AgentRefusal::class);
        app(ClientFieldWriter::class)->set($this->client, ['town' => '   ']);
    }

    public function test_numeric_option_keys_work_and_labels_must_name_one_option(): void
    {
        $definitions = app(ClientFieldDefinitions::class);
        $definitions->define($this->account->id, 'program', ['label' => 'Program', 'type' => 'select', 'options' => [['key' => '1', 'label' => 'Stare warunki'], ['key' => '2', 'label' => 'Nowe warunki']]]);
        app(ClientFieldWriter::class)->set($this->client, ['program' => 'nowe warunki']);
        $this->assertSame('2', $this->client->fresh()->custom_fields['program']);

        $this->expectException(AgentRefusal::class);
        $definitions->define($this->account->id, 'program', ['options' => [['key' => '3', 'label' => 'NOWE WARUNKI']]]);
    }

    public function test_an_archived_field_keeps_showing_its_value_to_agents_and_field_text_is_fenced(): void
    {
        app(ClientFieldWriter::class)->set($this->client, ['visit_status' => 'booked']);
        app(ClientFieldDefinitions::class)->define($this->account->id, 'visit_status', ['archived' => true]);

        Artisan::call('crm:brief', ['client' => (string) $this->client->id, '--json' => true]);
        $field = collect(json_decode(Artisan::output(), true)['fields'])->firstWhere('key', 'visit_status');
        $this->assertSame('booked', $field['value']);
        $this->assertTrue($field['archived']);

        Artisan::call('crm:client-fields', ['--all' => true]);
        $this->assertStringContainsString('BEGIN EXTERNAL CONTENT', Artisan::output());
    }

    public function test_the_text_brief_works_for_an_account_without_billing(): void
    {
        $this->account->update(['settings' => ['features' => ['billing' => false]]]);
        app(ClientFieldWriter::class)->set($this->client, ['visit_status' => 'to_book']);

        $this->assertSame(0, Artisan::call('crm:brief', ['client' => (string) $this->client->id]));
        $output = Artisan::output();
        $this->assertStringContainsString('Wizja: Do umówienia', $output);
        $this->assertStringNotContainsString('Hours bank', $output);
    }

    public function test_a_field_is_put_on_the_overview_and_taken_off_through_the_verb_and_the_tool(): void
    {
        Artisan::call('crm:client-field-define', ['key' => 'visit_status', '--overview' => true, '--json' => true]);
        $this->assertTrue(json_decode(Artisan::output(), true)['field']['on_overview']);

        CrmServer::tool(ClientFieldDefineTool::class, ['key' => 'visit_status', 'on_overview' => false])->assertOk();
        $this->assertFalse(app(ClientFieldDefinitions::class)->list($this->account->id)->firstWhere('key', 'visit_status')->on_overview);
    }
}
