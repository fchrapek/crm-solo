<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ClientRecordPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Client $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $account = Account::factory()->create();
        $this->user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $this->client = Client::factory()->create([
            'account_id' => $account->id,
            'type' => 'business',
            'name' => 'Pracownia Testowa Sp. z o.o.',
            // Check remainder 10 is never issued as a NIP, yet the NIP rule reads it as 0 and accepts the number.
            'tax_id' => '1234567890',
            'address' => 'ul. Przykładowa 1',
            'postal_code' => '00-950',
            'city' => 'Warszawa',
            'cooperation_type' => 'retainer',
            'month_close_type' => 'maintenance',
            'notes' => 'Keep this note',
            'hourly_rate' => 150,
            'external_ids' => ['infakt' => 10000123],
        ]);

        $other = Account::factory()->create();
        $this->stranger = Client::factory()->create(['account_id' => $other->id, 'name' => 'Other Co']);
    }

    /** @return array<string, array{string}> */
    public static function tabs(): array
    {
        return array_combine(
            ['przeglad', 'zadania', 'dziennik', 'rozliczenia', 'dane'],
            [['przeglad'], ['zadania'], ['dziennik'], ['rozliczenia'], ['dane']],
        );
    }

    public function test_the_bare_client_url_redirects_to_the_overview_tab(): void
    {
        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}")
            ->assertRedirect("/clients/{$this->client->id}/przeglad");
    }

    #[DataProvider('tabs')]
    public function test_every_tab_renders_the_record_page_with_the_header(string $tab): void
    {
        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/{$tab}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/show')
                ->where('tab', $tab)
                ->where('client.id', $this->client->id)
                ->where('client.name', 'Pracownia Testowa Sp. z o.o.')
                ->where('client.short_name', 'Pracownia Testowa')
                ->missing('client.lifecycle_stage')
            );
    }

    public function test_a_tab_outside_the_list_is_not_found(): void
    {
        $this->actingAs($this->user)->get("/clients/{$this->client->id}/faktury")->assertNotFound();
        $this->actingAs($this->user)->get("/clients/{$this->client->id}/Dane")->assertNotFound();
    }

    public function test_the_edit_page_still_resolves_beside_the_tab_route_and_the_old_create_address_opens_the_dialog_on_klienci(): void
    {
        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('clients/edit'));

        $this->actingAs($this->user)
            ->get('/clients/create')
            ->assertRedirect('/clients?nowy=1');
    }

    public function test_the_documents_and_settings_page_keeps_what_has_no_record_tab_and_drops_the_overview(): void
    {
        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/edit')
                ->hasAll(['client', 'projects', 'reports', 'reportComposers', 'reportBaselineMarkdown',
                    'invoices', 'invoiceFilters', 'revenue', 'timeEntries', 'clientTasks', 'lifecycleEvents', 'retainers', 'documents'])
                ->missing('overview'));
    }

    public function test_another_accounts_client_has_no_documents_and_settings_page(): void
    {
        $this->actingAs($this->user)->get("/clients/{$this->stranger->id}/edit")->assertNotFound();
    }

    public function test_another_accounts_client_is_not_found_on_the_bare_record_url(): void
    {
        $this->actingAs($this->user)->get("/clients/{$this->stranger->id}")->assertNotFound();
    }

    #[DataProvider('tabs')]
    public function test_another_accounts_client_is_not_found_on_any_tab(string $tab): void
    {
        $this->actingAs($this->user)->get("/clients/{$this->stranger->id}/{$tab}")->assertNotFound();
    }

    public function test_another_accounts_client_cannot_be_edited_or_moved_from_the_record(): void
    {
        $this->actingAs($this->user)
            ->put("/clients/{$this->stranger->id}", ['type' => 'business', 'name' => 'Taken over'])
            ->assertNotFound();
        $this->actingAs($this->user)
            ->patch("/clients/{$this->stranger->id}/stage", ['stage' => 'churned', 'note' => 'x'])
            ->assertNotFound();

        $stranger = $this->stranger->fresh();
        $this->assertSame('Other Co', $stranger->name);
        $this->assertSame('active', $stranger->lifecycle_stage);
        $this->assertSame(0, $stranger->lifecycleEvents()->where('note', 'x')->count());
    }

    public function test_a_contact_cannot_be_added_to_another_accounts_client(): void
    {
        $this->actingAs($this->user)
            ->from("/clients/{$this->client->id}/dane")
            ->post('/contacts', ['client_id' => $this->stranger->id, 'first_name' => 'Sneaky', 'last_name' => 'Contact'])
            ->assertRedirect("/clients/{$this->client->id}/dane")
            ->assertSessionHasErrors('client_id');

        $this->assertSame(0, Contact::withTrashed()->where('first_name', 'Sneaky')->count());
    }

    public function test_an_invalid_nip_is_refused_and_nothing_changes(): void
    {
        $this->actingAs($this->user)
            ->from("/clients/{$this->client->id}/dane")
            ->put("/clients/{$this->client->id}", ['type' => 'business', 'name' => 'Renamed', 'tax_id' => '1234567884'])
            ->assertRedirect("/clients/{$this->client->id}/dane")
            ->assertSessionHasErrors('tax_id');

        $client = $this->client->fresh();
        $this->assertSame('Pracownia Testowa Sp. z o.o.', $client->name);
        $this->assertSame('1234567890', $client->tax_id);
    }

    public function test_an_archived_client_still_opens_its_record_marked_archived(): void
    {
        $this->client->delete();

        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/dane")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/show')
                ->where('client.is_archived', true)
                ->where('details.name', 'Pracownia Testowa Sp. z o.o.')
            );
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get("/clients/{$this->client->id}/dane")->assertRedirect('/login');
    }

    public function test_the_data_tab_carries_the_company_record_and_the_infakt_id(): void
    {
        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/dane")
            ->assertInertia(fn (Assert $page) => $page
                ->where('details.type', 'business')
                ->where('details.name', 'Pracownia Testowa Sp. z o.o.')
                ->where('details.tax_id', '1234567890')
                ->where('details.address', 'ul. Przykładowa 1')
                ->where('details.postal_code', '00-950')
                ->where('details.city', 'Warszawa')
                ->where('details.cooperation_type', 'retainer')
                ->where('details.month_close_type', 'maintenance')
                ->where('details.infakt_id', '10000123')
            );
    }

    public function test_a_client_not_linked_to_infakt_has_no_infakt_id(): void
    {
        $this->client->update(['external_ids' => ['infakt_synced' => ['name' => 'x']]]);

        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/dane")
            ->assertInertia(fn (Assert $page) => $page->where('details.infakt_id', null));
    }

    public function test_the_data_tab_lists_only_this_clients_contacts_with_their_first_email(): void
    {
        $this->user->account->contacts()->createMany([
            ['client_id' => $this->client->id, 'first_name' => 'Zofia', 'last_name' => 'Próbna', 'emails' => ['zofia@example.test', 'j2@example.test']],
            ['client_id' => $this->client->id, 'first_name' => 'Adam', 'last_name' => 'Abacki', 'phone' => '600100200'],
        ]);
        Contact::create(['account_id' => $this->stranger->account_id, 'client_id' => $this->stranger->id, 'first_name' => 'Secret', 'last_name' => 'Person']);

        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/dane")
            ->assertInertia(fn (Assert $page) => $page
                ->has('contacts', 2)
                ->where('contacts.0.name', 'Adam Abacki')
                ->where('contacts.0.email', null)
                ->where('contacts.0.phone', '600100200')
                ->where('contacts.1.name', 'Zofia Próbna')
                ->where('contacts.1.email', 'zofia@example.test')
            );
    }

    public function test_only_the_active_tab_computes_its_props(): void
    {
        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/przeglad")
            ->assertInertia(fn (Assert $page) => $page
                ->missing('details')
                ->missing('contacts')
            );
    }

    public function test_the_tasks_tab_label_counts_open_tasks(): void
    {
        $project = Project::create(['account_id' => $this->client->account_id, 'client_id' => $this->client->id, 'name' => 'Site']);
        Task::create(['project_id' => $project->id, 'name' => 'Open one', 'source' => 'manual', 'list_name' => 'To-Do']);
        Task::create(['project_id' => $project->id, 'name' => 'Open two', 'source' => 'manual', 'list_name' => 'Doing']);
        Task::create(['project_id' => $project->id, 'name' => 'Done', 'source' => 'manual', 'list_name' => 'To-Do', 'finished_at' => now()]);

        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/dane")
            ->assertInertia(fn (Assert $page) => $page->where('client.open_tasks_count', 2));
    }

    public function test_the_header_says_whether_the_client_is_pinned_on_the_list(): void
    {
        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/przeglad")
            ->assertInertia(fn (Assert $page) => $page->where('client.is_pinned', false));

        $this->client->update(['is_pinned' => true]);

        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/przeglad")
            ->assertInertia(fn (Assert $page) => $page->where('client.is_pinned', true));
    }

    public function test_a_running_agent_timer_shows_as_live_in_the_header_and_a_person_timer_does_not(): void
    {
        $project = Project::create(['account_id' => $this->client->account_id, 'client_id' => $this->client->id, 'name' => 'Site']);
        $task = Task::create(['project_id' => $project->id, 'name' => 'Work', 'source' => 'manual', 'list_name' => 'Doing']);

        $this->travelTo(now()->startOfMinute());
        $person = TimeEntry::startFor($task);

        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/dane")
            ->assertInertia(fn (Assert $page) => $page->where('client.live_agent_minutes', null));

        $person->forceFill(['start_time' => now()->subMinutes(14), 'actor_via' => 'cli'])->save();

        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/dane")
            ->assertInertia(fn (Assert $page) => $page->where('client.live_agent_minutes', 14));
    }

    public function test_a_new_client_lands_on_its_data_tab(): void
    {
        $response = $this->actingAs($this->user)->post('/clients', ['type' => 'business', 'name' => 'Fresh Co']);

        $client = Client::where('name', 'Fresh Co')->firstOrFail();
        $response->assertRedirect("/clients/{$client->id}/dane");
    }

    public function test_editing_the_company_section_keeps_the_fields_it_does_not_send(): void
    {
        $this->actingAs($this->user)
            ->from("/clients/{$this->client->id}/dane")
            ->put("/clients/{$this->client->id}", [
                'type' => 'business',
                'name' => 'Pracownia Testowa S.A.',
                'tax_id' => '1234567890',
                'address' => 'ul. Nowa 2',
                'postal_code' => '00-950',
                'city' => 'Warszawa',
                'country' => 'PL',
                'email' => '',
                'phone' => '',
                'cooperation_type' => 'hourly',
            ])
            ->assertRedirect("/clients/{$this->client->id}/dane")
            ->assertSessionHasNoErrors();

        $client = $this->client->fresh();
        $this->assertSame('Pracownia Testowa S.A.', $client->name);
        $this->assertSame('ul. Nowa 2', $client->address);
        $this->assertSame('hourly', $client->cooperation_type);
        $this->assertSame('Keep this note', $client->notes);
        $this->assertEquals(150, $client->hourly_rate);
        $this->assertSame('maintenance', $client->month_close_type);
    }

    public function test_editing_the_company_section_without_a_type_is_refused(): void
    {
        $this->actingAs($this->user)
            ->from("/clients/{$this->client->id}/dane")
            ->put("/clients/{$this->client->id}", ['name' => 'No Type'])
            ->assertSessionHasErrors('type');
    }

    public function test_a_contact_added_from_the_record_returns_to_the_record(): void
    {
        $this->actingAs($this->user)
            ->from("/clients/{$this->client->id}/dane")
            ->post('/contacts', [
                'client_id' => $this->client->id,
                'first_name' => 'Tomasz',
                'last_name' => 'Testowy',
                'emails' => ['tomasz@example.test'],
            ])
            ->assertRedirect("/clients/{$this->client->id}/dane");

        $this->assertSame(1, $this->client->contacts()->count());
    }

    public function test_a_contact_added_on_kontakty_returns_to_the_list(): void
    {
        $this->actingAs($this->user)
            ->from('/contacts?search=nowak')
            ->post('/contacts', ['client_id' => $this->client->id, 'first_name' => 'Jan', 'last_name' => 'Nowak'])
            ->assertRedirect('/contacts?search=nowak');
    }

    public function test_converting_a_won_lead_opens_the_new_clients_data_and_a_second_convert_opens_its_overview(): void
    {
        $lead = Lead::create([
            'account_id' => $this->user->account_id,
            'pipeline' => Lead::pipelines()[0],
            'source' => config('leadgen.sources')[0],
            'name' => 'Won Lead',
            'company' => 'Won Co',
        ]);
        $lead->transitionTo('won', null, $this->user);

        $first = $this->actingAs($this->user)->post("/leads/{$lead->id}/convert");
        $client = Client::where('name', 'Won Co')->firstOrFail();
        $first->assertRedirect("/clients/{$client->id}/dane");

        $this->actingAs($this->user)
            ->post("/leads/{$lead->id}/convert")
            ->assertRedirect("/clients/{$client->id}/przeglad");
    }

    public function test_dane_carries_the_month_close_and_report_settings_and_the_documents_the_old_page_held(): void
    {
        $this->client->update([
            'month_close_type' => 'maintenance',
            'report_mode' => 'report',
            'include_in_month_close' => true,
            'ssh_config' => 'meble-prod',
            'maintenance_invoice_description' => 'Utrzymanie strony',
            'report_baseline_markdown' => 'Kopie zapasowe',
            'segment' => 'smb',
            'hourly_rate' => 165,
        ]);

        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/dane")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('details.ssh_config', 'meble-prod')
                ->where('details.maintenance_invoice_description', 'Utrzymanie strony')
                ->where('details.report_baseline_markdown', 'Kopie zapasowe')
                ->where('details.include_in_month_close', true)
                ->where('details.segment', 'smb')
                ->where('details.hourly_rate', 165)
                ->where('details.archived', false)
                ->has('documents', 0));
    }

    public function test_report_and_document_days_are_read_in_the_display_timezone_not_utc(): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);
        $report = \App\Models\ClientReport::create([
            'account_id' => $this->client->account_id, 'client_id' => $this->client->id, 'period_type' => \App\Models\ClientReport::PERIOD_MONTH,
            'status' => \App\Models\ClientReport::STATUS_FINALIZED, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'opening_balance_hours' => 0, 'actual_hours' => 2, 'composer_key' => 'manual',
            'finalized_at' => '2026-10-09 22:30:00', 'generated_at' => '2026-10-09 22:30:00',
        ]);

        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/reports/{$report->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.finalized_on', '2026-10-10')
                ->where('report.generated_on', '2026-10-10'));
    }
}
