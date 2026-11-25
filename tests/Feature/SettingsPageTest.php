<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\SettingsController;
use App\Models\Account;
use App\Models\ClientFieldDefinition;
use App\Models\Integration;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Settings: the account's page, a tab per area, for its owner. */
final class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $account = Account::factory()->create();
        $this->owner = User::factory()->create(['account_id' => $account->id, 'owner' => true, 'first_name' => 'Ewa', 'last_name' => 'Owner']);
    }

    public function test_the_section_list_carries_the_summaries_and_the_general_tab_for_desktop(): void
    {
        User::factory()->create(['account_id' => $this->owner->account_id]);
        User::factory()->create(['account_id' => Account::factory()->create()->id]);
        Setting::create(['account_id' => $this->owner->account_id, 'scope' => 'leadgen', 'data' => ['custom_sources' => ['gbp'], 'source_labels' => []]]);
        Integration::create(['account_id' => $this->owner->account_id, 'provider' => 'infakt', 'api_key' => 'key', 'is_enabled' => true]);
        Integration::create(['account_id' => $this->owner->account_id, 'provider' => Integration::SMTP, 'api_key' => 'pass', 'is_enabled' => true]);

        $this->actingAs($this->owner)->get('/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/index')
                ->where('tab', null)
                ->where('summary.users', 2)
                ->where('summary.custom_sources', 1)
                ->where('summary.connected', ['Infakt'])
                ->where('summary.tokens.count', 0)
                ->missing('me')
                ->has('users', 2)
                ->has('modules')
                ->has('client_fields'));
    }

    public function test_general_lists_only_the_accounts_users_and_marks_the_viewer(): void
    {
        $member = User::factory()->create(['account_id' => $this->owner->account_id, 'owner' => false]);
        $member->delete();
        User::factory()->create(['account_id' => Account::factory()->create()->id, 'first_name' => 'Stranger']);

        $this->actingAs($this->owner)->get('/settings/general')
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'general')
                ->has('users', 2)
                ->where('users', fn ($users) => collect($users)->pluck('first_name')->doesntContain('Stranger'))
                ->where('users', fn ($users) => collect($users)->firstWhere('id', $this->owner->id)['is_me'] === true
                    && collect($users)->firstWhere('id', $this->owner->id)['can_delete'] === false
                    && collect($users)->firstWhere('id', $member->id)['deleted_at'] !== null
                    && collect($users)->firstWhere('id', $member->id)['can_delete'] === true));
    }

    public function test_leads_shows_the_shipped_defaults_and_the_accounts_custom_sources(): void
    {
        Setting::create(['account_id' => $this->owner->account_id, 'scope' => 'leadgen', 'data' => ['custom_sources' => ['gbp'], 'source_labels' => ['gbp' => 'Wizytówka Google']]]);

        $this->actingAs($this->owner)->get('/settings/leads')
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'leads')
                ->where('leadgen.custom_sources', ['gbp'])
                ->where('leadgen.source_labels.gbp', 'Wizytówka Google')
                ->where('leadgen.default_sources', array_values((require config_path('leadgen.php'))['sources']))
                ->missing('users'));
    }

    public function test_integrations_says_whether_server_values_are_set_and_never_the_values(): void
    {
        config([
            'services.openai.api_key' => 'sk-secret-value',
            'services.kiwwwi.leads.username' => 'crm',
            'services.kiwwwi.leads.app_password' => 'app-password-value',
            'services.kiwwwi.leads.endpoints.pl.base_url' => 'https://example.test',
            'services.kiwwwi.leads.endpoints.en.base_url' => null,
        ]);
        Integration::create(['account_id' => $this->owner->account_id, 'provider' => 'trello', 'api_key' => 'trello-token-value', 'is_enabled' => true, 'settings' => ['trello_api_key' => 'trello-key-value']]);

        $response = $this->actingAs($this->owner)->get('/settings/integrations')
            ->assertInertia(fn (Assert $page) => $page
                ->where('server.openai', true)
                ->where('server.kiwwwi_forms', ['polled' => 1, 'total' => 2])
                ->where('integrations.1.provider', 'trello')
                ->where('integrations.1.is_configured', true)
                ->where('integrations.1.boards', 0));

        foreach (['sk-secret-value', 'app-password-value', 'trello-token-value', 'trello-key-value'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_an_unknown_tab_is_not_found_and_a_guest_is_sent_to_login(): void
    {
        $this->actingAs($this->owner)->get('/settings/profil')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->get('/settings/general')->assertRedirect('/login');
    }

    public function test_the_old_write_routes_keep_working_and_return_to_the_tab(): void
    {
        $this->actingAs($this->owner)->from('/settings/general')
            ->post('/users', ['first_name' => 'Nowa', 'last_name' => 'Osoba', 'email' => 'nowa@example.test', 'password' => 'a-long-password-1', 'owner' => false])
            ->assertRedirect('/settings/general');
        $this->assertTrue(User::query()->where('email', 'nowa@example.test')->where('owner', false)->exists());

        $this->actingAs($this->owner)->from('/settings/leads')
            ->patch('/settings/leadgen', ['custom_sources' => ['newsletter'], 'source_labels' => ['newsletter' => 'Newsletter']])
            ->assertRedirect('/settings/leads');
    }

    public function test_the_five_tabs_open_on_one_page_each_with_what_it_holds(): void
    {
        $this->assertSame(['general', 'leads', 'forms', 'messages', 'integrations'], SettingsController::TABS);

        $holds = [
            'general' => ['users', 'modules', 'client_fields', 'reserved_field_keys'],
            'leads' => ['leadgen'],
            'forms' => ['intake'],
            'messages' => ['sending', 'templates', 'rules', 'fields', 'email_templates', 'email_sending'],
            'integrations' => ['integrations', 'server', 'tokens', 'abilities'],
        ];
        foreach ($holds as $tab => $props) {
            $this->actingAs($this->owner)->get("/settings/{$tab}")->assertOk()
                ->assertInertia(function (Assert $page) use ($tab, $props): void {
                    $page->component('settings/index')->where('tab', $tab);
                    foreach ($props as $prop) {
                        $page->has($prop);
                    }
                });
        }
    }

    public function test_messages_holds_the_text_messages_and_without_billing_no_email(): void
    {
        $account = $this->owner->account;
        $account->settings = [...(array) $account->settings, 'features' => ['billing' => false]];
        $account->save();

        $this->actingAs($this->owner)->get('/settings/messages')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('sending')->has('rules')->missing('email_templates')->missing('email_sending'));
        $this->actingAs($this->owner)->get('/settings')
            ->assertInertia(fn (Assert $page) => $page->where('summary.email_templates', null));
    }

    public function test_a_member_finds_no_settings_page_at_all(): void
    {
        $member = User::factory()->create(['account_id' => $this->owner->account_id, 'owner' => false]);

        $this->actingAs($member)->get('/settings')->assertNotFound();
        foreach (SettingsController::TABS as $tab) {
            $this->actingAs($member)->get("/settings/{$tab}")->assertNotFound();
        }
    }

    public function test_an_owner_sees_only_their_own_accounts_settings(): void
    {
        $stranger = User::factory()->create(['account_id' => Account::factory()->create()->id, 'owner' => true, 'first_name' => 'Obca']);
        ClientFieldDefinition::create(['account_id' => $stranger->account_id, 'key' => 'theirs', 'label' => 'Ich pole', 'type' => 'text']);

        $this->actingAs($this->owner)->get('/settings/general')
            ->assertInertia(fn (Assert $page) => $page
                ->where('users', fn ($users) => collect($users)->pluck('first_name')->all() === ['Ewa'])
                ->has('client_fields', 0)
                ->where('modules.client_fields', 0));
    }
}
