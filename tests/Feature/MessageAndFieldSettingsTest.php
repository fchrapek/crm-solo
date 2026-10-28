<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ClientFieldDefinition;
use App\Models\MessageRule;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Clients\Fields\ClientFieldDefinitions;
use App\Services\Messaging\MessageSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Ustawienia: client fields and text messages edited in the app, through the services the verbs use. */
final class MessageAndFieldSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $account = Account::factory()->create();
        $this->user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        app(ClientFieldDefinitions::class)->define($account->id, 'visit_at', ['label' => 'Termin wizji', 'type' => 'datetime', 'on_overview' => true]);
    }

    public function test_the_fields_tab_lists_the_account_fields_and_a_new_one_is_added(): void
    {
        $this->actingAs($this->user)->get('/settings/pola-klienta')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('client_fields.0.key', 'visit_at')->where('client_fields.0.on_overview', true));

        $this->actingAs($this->user)->put('/settings/client-fields/visit_status', [
            'label' => 'Wizja', 'type' => 'select', 'options' => [['key' => 'booked', 'label' => 'Umówiona']], 'required' => true, 'on_overview' => true,
        ])->assertSessionHasNoErrors();

        $field = ClientFieldDefinition::query()->where('key', 'visit_status')->sole();
        $this->assertSame([true, true, ['booked' => 'Umówiona']], [$field->required, $field->on_overview, $field->optionLabels()]);
    }

    public function test_a_refused_field_change_comes_back_as_a_form_error(): void
    {
        $this->actingAs($this->user)->put('/settings/client-fields/visit_at', ['type' => 'text'])->assertSessionHasErrors('key');
    }

    public function test_a_choice_field_without_options_is_refused_under_the_options_in_polish(): void
    {
        $this->actingAs($this->user)->withUnencryptedCookie('locale', 'pl')->put('/settings/client-fields/priority', ['label' => 'Priorytet', 'type' => 'select', 'options' => []])
            ->assertSessionHasErrors(['options' => 'Pole wyboru potrzebuje co najmniej jednej opcji.']);
        $this->assertFalse(ClientFieldDefinition::query()->where('key', 'priority')->exists());
    }

    public function test_templates_and_rules_are_added_changed_and_deleted_from_the_sms_tab(): void
    {
        $this->actingAs($this->user)->post('/settings/sms/templates', ['name' => 'Przypomnienie dzień przed', 'body' => 'Wizja {field:visit_at}.'])->assertSessionHasNoErrors();
        $template = MessageTemplate::query()->sole();
        $this->assertSame('przypomnienie_dzien_przed', $template->key);

        $this->actingAs($this->user)->post('/settings/sms/rules', [
            'template' => $template->key, 'trigger' => 'date_offset', 'field' => 'visit_at', 'offset_minutes' => -1440, 'at' => '18:00', 'expire_minutes' => 60, 'enabled' => true,
        ])->assertSessionHasNoErrors();
        $rule = MessageRule::query()->sole();
        $this->assertSame(['field' => 'visit_at', 'offset_minutes' => -1440, 'at' => '18:00'], $rule->trigger_config);

        $this->actingAs($this->user)->put("/settings/sms/rules/{$rule->key}", [
            'template' => $template->key, 'trigger' => 'date_offset', 'field' => 'visit_at', 'offset_minutes' => -2880, 'at' => null, 'expire_minutes' => 60, 'enabled' => true,
        ])->assertSessionHasNoErrors();
        $this->assertNull($rule->fresh()->trigger_config['at'], 'an empty time clears it');

        $this->actingAs($this->user)->get('/settings/sms')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('templates.0.rules', 1)->where('rules.0.offset_minutes', -2880)->where('sending.ascii', false));

        $this->actingAs($this->user)->delete("/settings/sms/templates/{$template->key}")->assertSessionHasErrors('form');
        $this->actingAs($this->user)->delete("/settings/sms/rules/{$rule->key}")->assertSessionHasNoErrors();
        $this->actingAs($this->user)->delete("/settings/sms/templates/{$template->key}")->assertSessionHasNoErrors();
        $this->assertSame(0, MessageTemplate::query()->count());
    }

    public function test_quiet_hours_and_polish_letters_are_saved_and_the_preview_counts_the_messages(): void
    {
        $this->actingAs($this->user)->patch('/settings/sms/sending', ['quiet_from' => '21:00', 'quiet_to' => '07:30', 'ascii' => true])->assertSessionHasNoErrors();
        $this->assertSame(['quiet_from' => '21:00', 'quiet_to' => '07:30', 'ascii' => true, 'provider' => 'simulator'], app(MessageSettings::class)->sendingOf($this->user->account->fresh()));

        $this->actingAs($this->user)->postJson('/settings/sms/preview', ['body' => 'Zażółć'])->assertOk()->assertJson(['text' => 'Zazolc', 'segments' => 1, 'unicode' => false]);
        $this->actingAs($this->user)->postJson('/settings/sms/preview', ['body' => '{field:nope}'])->assertUnprocessable()->assertJsonValidationErrors('body');
    }

    public function test_another_accounts_template_cannot_be_changed(): void
    {
        $other = Account::factory()->create();
        app(MessageSettings::class)->template($other->id, 'theirs', 'Ich', 'Tekst');

        $this->actingAs($this->user)->put('/settings/sms/templates/theirs', ['name' => 'Moje', 'body' => 'Nowy'])->assertNotFound();
        $this->assertSame('Ich', MessageTemplate::query()->sole()->name);
    }
}
