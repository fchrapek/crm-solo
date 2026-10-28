<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Integration;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Ustawienia: one page, a tab per area, and the old settings URLs landing on their tab. */
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

    /** @return array<string, array{string, string}> */
    public static function oldUrls(): array
    {
        return [
            'users' => ['/users', '/settings/konto'],
            'new user' => ['/users/create', '/settings/konto'],
            'edit user' => ['/users/1/edit', '/settings/konto'],
            'integrations' => ['/integrations', '/settings/integracje'],
            'one integration' => ['/integrations/trello', '/settings/integracje'],
        ];
    }

    public function test_the_section_list_carries_the_summaries_and_the_konto_tab_for_desktop(): void
    {
        User::factory()->create(['account_id' => $this->owner->account_id]);
        User::factory()->create(['account_id' => Account::factory()->create()->id]);
        Setting::create(['account_id' => $this->owner->account_id, 'scope' => 'leadgen', 'data' => ['custom_sources' => ['gbp'], 'source_labels' => []]]);
        Integration::create(['account_id' => $this->owner->account_id, 'provider' => 'infakt', 'api_key' => 'key', 'is_enabled' => true]);

        $this->actingAs($this->owner)->get('/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/index')
                ->where('tab', null)
                ->where('summary.users', 2)
                ->where('summary.custom_sources', 1)
                ->where('summary.connected', ['Infakt'])
                ->where('summary.tokens.count', 0)
                ->where('me.first_name', 'Ewa')
                ->has('users', 2));
    }

    public function test_konto_lists_only_the_accounts_users_and_marks_the_viewer(): void
    {
        $member = User::factory()->create(['account_id' => $this->owner->account_id, 'owner' => false]);
        $member->delete();
        User::factory()->create(['account_id' => Account::factory()->create()->id, 'first_name' => 'Stranger']);

        $this->actingAs($this->owner)->get('/settings/konto')
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'konto')
                ->has('users', 2)
                ->where('users', fn ($users) => collect($users)->pluck('first_name')->doesntContain('Stranger'))
                ->where('users', fn ($users) => collect($users)->firstWhere('id', $this->owner->id)['is_me'] === true
                    && collect($users)->firstWhere('id', $this->owner->id)['can_delete'] === false
                    && collect($users)->firstWhere('id', $member->id)['deleted_at'] !== null
                    && collect($users)->firstWhere('id', $member->id)['can_delete'] === true));
    }

    public function test_leady_shows_the_shipped_defaults_and_the_accounts_custom_sources(): void
    {
        Setting::create(['account_id' => $this->owner->account_id, 'scope' => 'leadgen', 'data' => ['custom_sources' => ['gbp'], 'source_labels' => ['gbp' => 'Wizytówka Google']]]);

        $this->actingAs($this->owner)->get('/settings/leady')
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'leady')
                ->where('leadgen.custom_sources', ['gbp'])
                ->where('leadgen.source_labels.gbp', 'Wizytówka Google')
                ->where('leadgen.default_sources', array_values((require config_path('leadgen.php'))['sources']))
                ->missing('users'));
    }

    public function test_integracje_says_whether_server_values_are_set_and_never_the_values(): void
    {
        config([
            'services.openai.api_key' => 'sk-secret-value',
            'services.kiwwwi.leads.username' => 'crm',
            'services.kiwwwi.leads.app_password' => 'app-password-value',
            'services.kiwwwi.leads.endpoints.pl.base_url' => 'https://example.test',
            'services.kiwwwi.leads.endpoints.en.base_url' => null,
        ]);
        Integration::create(['account_id' => $this->owner->account_id, 'provider' => 'trello', 'api_key' => 'trello-token-value', 'is_enabled' => true, 'settings' => ['trello_api_key' => 'trello-key-value']]);

        $response = $this->actingAs($this->owner)->get('/settings/integracje')
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

    #[DataProvider('oldUrls')]
    public function test_an_old_settings_url_lands_on_its_tab(string $old, string $tab): void
    {
        $this->actingAs($this->owner)->get($old)->assertRedirect($tab);
    }

    public function test_an_unknown_tab_is_not_found_and_a_guest_is_sent_to_login(): void
    {
        $this->actingAs($this->owner)->get('/settings/profil')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->get('/settings/konto')->assertRedirect('/login');
    }

    public function test_the_old_write_routes_keep_working_and_return_to_the_tab(): void
    {
        $this->actingAs($this->owner)->from('/settings/konto')
            ->post('/users', ['first_name' => 'Nowa', 'last_name' => 'Osoba', 'email' => 'nowa@example.test', 'password' => 'a-long-password-1', 'owner' => false])
            ->assertRedirect('/settings/konto');
        $this->assertTrue(User::query()->where('email', 'nowa@example.test')->where('owner', false)->exists());

        $this->actingAs($this->owner)->from('/settings/leady')
            ->patch('/settings/leadgen', ['custom_sources' => ['newsletter'], 'source_labels' => ['newsletter' => 'Newsletter']])
            ->assertRedirect('/settings/leady');
    }
}
