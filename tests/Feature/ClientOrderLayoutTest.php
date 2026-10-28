<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientMonthClosure;
use App\Models\ClientMonthSettlementEvent;
use App\Models\ClientReport;
use App\Models\ClientRetainer;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Agent\ClientBrief;
use App\Services\Clients\ClientBillingMode;
use App\Services\Clients\HoursBank;
use App\Services\Integrations\Trello\TrelloListMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** D8: a client without retainer evidence reads in the Zlecenie layout on Przegląd and Rozliczenia. */
final class ClientOrderLayoutTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.display_timezone' => 'Europe/Warsaw']);
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'UTC'));

        $this->account = Account::create(['name' => 'Acme']);
        $this->user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->client = $this->account->clients()->create(['name' => 'Jan Przykładowy', 'type' => 'individual', 'currency' => 'PLN']);
    }

    public function test_a_client_without_retainer_evidence_is_in_the_order_layout(): void
    {
        $this->assertSame(ClientBillingMode::ORDER, $this->mode());
    }

    public function test_any_retainer_position_puts_the_client_in_the_retainer_layout_even_one_starting_next_month(): void
    {
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'monthly_hours' => 10, 'effective_from' => '2026-11-01']);

        $this->assertSame(ClientBillingMode::RETAINER, $this->mode());
    }

    public function test_a_closure_keeps_the_retainer_layout(): void
    {
        ClientMonthClosure::create(['client_id' => $this->client->id, 'period' => '2026-08', 'closed_at' => now(), 'snapshot' => []]);

        $this->assertSame(ClientBillingMode::RETAINER, $this->mode());
    }

    public function test_a_settlement_event_keeps_the_retainer_layout_after_its_closure_is_gone(): void
    {
        ClientMonthSettlementEvent::create(['client_id' => $this->client->id, 'period' => '2026-08', 'action' => 'reopen', 'snapshot' => [], 'created_at' => now()]);

        $this->assertSame(ClientBillingMode::RETAINER, $this->mode());
    }

    public function test_a_whole_month_report_with_contracted_hours_keeps_the_retainer_layout(): void
    {
        $this->report('2026-08-01', '2026-08-31', 10);

        $this->assertSame(ClientBillingMode::RETAINER, $this->mode());
    }

    public function test_a_report_without_contracted_hours_or_covering_part_of_a_month_leaves_the_order_layout(): void
    {
        $this->report('2026-08-01', '2026-08-31', null);
        $this->report('2026-09-10', '2026-09-30', 10);

        $this->assertSame(ClientBillingMode::ORDER, $this->mode());
    }

    public function test_the_mode_is_read_for_many_clients_in_one_batch(): void
    {
        $retainer = $this->account->clients()->create(['name' => 'Abonament']);
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $retainer->id, 'monthly_hours' => 5, 'effective_from' => '2026-01-01']);

        $this->assertSame(
            [$this->client->id => ClientBillingMode::ORDER, $retainer->id => ClientBillingMode::RETAINER],
            app(ClientBillingMode::class)->forIds([$this->client->id, $retainer->id]),
        );
    }

    public function test_the_header_names_the_layout_on_every_tab(): void
    {
        foreach (['przeglad', 'zadania', 'dziennik', 'rozliczenia', 'dane'] as $tab) {
            $this->actingAs($this->user)
                ->get("/clients/{$this->client->id}/{$tab}")
                ->assertInertia(fn (Assert $page) => $page->where('client.billing_mode', ClientBillingMode::ORDER));
        }
    }

    public function test_przeglad_and_rozliczenia_carry_the_same_order_block(): void
    {
        $this->client->generalProject()->update(['name' => 'Audyt energetyczny domu']);
        $this->entry('2026-09-15 08:00:00', 390);
        $this->entry('2026-10-01 08:00:00', 210);

        $overview = $this->props('przeglad')['overview']['order'];
        $billing = $this->props('rozliczenia')['billing']['order'];

        $this->assertSame($overview, $billing);
        $this->assertSame('Audyt energetyczny domu', $overview['title']);
        $this->assertSame('2026-09-15', $overview['started_on']);
        $this->assertSame(10.0, $overview['hours']);
        $this->assertSame(3.5, $overview['month_hours']);
    }

    public function test_the_order_carries_the_catch_all_projects_identity_and_scope_for_its_editor(): void
    {
        $project = $this->client->generalProject();
        $project->update(['name' => 'Audyt energetyczny domu', 'description' => 'Dom jednorodzinny, 164 m2', 'backup_path' => '/kopie/audyt', 'include_in_month_close' => true]);

        $order = $this->props('rozliczenia')['billing']['order'];

        $this->assertSame($project->id, $order['project']['id']);
        $this->assertSame('Audyt energetyczny domu', $order['project']['name']);
        $this->assertSame('Dom jednorodzinny, 164 m2', $order['project']['description']);
        $this->assertSame('/kopie/audyt', $order['project']['backup_path'], 'the editor sends it back, so it must arrive');
        $this->assertTrue($order['project']['include_in_month_close']);
    }

    public function test_the_title_falls_back_to_the_short_client_name_while_the_project_is_still_general(): void
    {
        $company = $this->account->clients()->create(['name' => 'Pracownia Przykładowa Sp. z o.o.']);

        $this->assertSame('Jan Przykładowy', $this->props('przeglad')['overview']['order']['title']);
        $this->assertSame('Pracownia Przykładowa', $this->props('przeglad', $company)['overview']['order']['title']);
    }

    public function test_time_counts_every_entry_of_the_client_whatever_its_project(): void
    {
        $other = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Board', 'trello_board_id' => 'b1']);
        $this->entry('2026-09-20 08:00:00', 60);
        $this->entry('2026-09-21 08:00:00', 30, $other);

        $this->assertSame(1.5, $this->props('przeglad')['overview']['order']['hours']);
    }

    public function test_progress_counts_done_under_the_completion_rules_including_trello_done_and_archived(): void
    {
        $project = $this->client->generalProject();
        Task::create(['project_id' => $project->id, 'name' => 'Open', 'source' => 'manual']);
        Task::create(['project_id' => $project->id, 'name' => 'Ticked', 'source' => 'manual', 'finished_at' => now()]);
        Task::create(['project_id' => $project->id, 'name' => 'On Done', 'source' => 'manual', 'list_name' => TrelloListMapper::LANE_DONE]);
        Task::create(['project_id' => $project->id, 'name' => 'Archived', 'source' => 'manual', 'archived_at' => now()]);

        $order = $this->props('przeglad')['overview']['order'];

        $this->assertSame(['done' => 3, 'total' => 4], $order['tasks']);
        $this->assertSame('in_progress', $order['state']);
    }

    public function test_an_order_with_every_task_done_reads_done_and_one_without_tasks_has_no_state(): void
    {
        $this->assertNull($this->props('przeglad')['overview']['order']['state']);

        Task::create(['project_id' => $this->client->generalProject()->id, 'name' => 'Ticked', 'source' => 'manual', 'finished_at' => now()]);

        $this->assertSame('done', $this->props('przeglad')['overview']['order']['state']);
    }

    public function test_the_poster_reads_the_latest_invoice_by_issue_date_in_gross_currency_units(): void
    {
        $this->invoice('2/09/2026', 'sent', '2026-09-29', payment: '2026-10-14', gross: 240000, net: 195122);
        $this->invoice('1/10/2026', 'paid', '2026-09-01', gross: 50000, net: 40650);

        $invoice = $this->props('przeglad')['overview']['order']['invoice'];

        $this->assertSame('2/09/2026', $invoice['number']);
        $this->assertSame(2400.0, $invoice['gross']);
        $this->assertSame(1951.22, $invoice['net']);
        $this->assertSame('unpaid', $invoice['state']);
        $this->assertSame('2026-10-14', $invoice['payment_date']);
        $this->assertFalse($invoice['overdue']);
    }

    public function test_invoices_issued_the_same_day_resolve_to_the_newest_row_and_an_undated_one_never_outranks_a_dated_one(): void
    {
        $this->invoice('7/09/2026', 'sent', '2026-09-29');
        $this->invoice('8/09/2026', 'sent', '2026-09-29');
        Invoice::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'external_id' => 'undated', 'number' => null, 'status' => 'draft', 'invoice_date' => null, 'gross_price' => 100]);

        $this->assertSame('8/09/2026', $this->props('przeglad')['overview']['order']['invoice']['number']);

        Invoice::query()->whereNotNull('invoice_date')->delete();

        $invoice = $this->props('przeglad')['overview']['order']['invoice'];
        $this->assertNull($invoice['number']);
        $this->assertNull($invoice['invoice_date']);
        $this->assertSame('draft', $invoice['state']);
    }

    public function test_an_invoice_is_overdue_only_once_its_payment_day_has_passed_in_warsaw(): void
    {
        $this->invoice('1/09/2026', 'sent', '2026-09-20', payment: '2026-10-05');

        $this->travelTo(Carbon::parse('2026-10-05 21:59:00', 'UTC'));
        $this->assertFalse($this->props('przeglad')['overview']['order']['invoice']['overdue'], 'still the payment day in Warsaw');

        $this->travelTo(Carbon::parse('2026-10-05 22:01:00', 'UTC'));
        $this->assertTrue($this->props('przeglad')['overview']['order']['invoice']['overdue'], 'the next day in Warsaw');

        Invoice::query()->update(['status' => 'paid']);
        $this->assertFalse($this->props('przeglad')['overview']['order']['invoice']['overdue']);
        Invoice::query()->update(['status' => 'draft']);
        $this->assertFalse($this->props('przeglad')['overview']['order']['invoice']['overdue']);
    }

    public function test_invoice_statuses_map_to_draft_unpaid_and_paid(): void
    {
        foreach (['draft' => 'draft', 'sent' => 'unpaid', 'printed' => 'unpaid', 'paid' => 'paid'] as $status => $state) {
            Invoice::query()->delete();
            $this->invoice('1/09/2026', $status, '2026-09-29');

            $this->assertSame($state, $this->props('przeglad')['overview']['order']['invoice']['state'], $status);
        }
    }

    public function test_an_order_without_an_invoice_has_none(): void
    {
        $this->assertNull($this->props('rozliczenia')['billing']['order']['invoice']);
    }

    public function test_czeka_na_ciebie_names_the_next_dated_open_task_and_its_running_timer(): void
    {
        $project = $this->client->generalProject();
        Task::create(['project_id' => $project->id, 'name' => 'Bez terminu', 'source' => 'manual']);
        Task::create(['project_id' => $project->id, 'name' => 'Zrobione', 'source' => 'manual', 'due_date' => '2026-10-01', 'finished_at' => now()]);
        $later = Task::create(['project_id' => $project->id, 'name' => 'Przekazanie raportu', 'source' => 'manual', 'due_date' => '2026-10-14']);
        $next = Task::create(['project_id' => $project->id, 'name' => 'Raport z audytu', 'source' => 'manual', 'due_date' => '2026-10-09']);

        $task = $this->props('przeglad')['overview']['order_waiting']['task'];
        $this->assertSame(['task_id' => $next->id, 'name' => 'Raport z audytu', 'due' => '2026-10-09', 'running_id' => null], $task);

        $running = TimeEntry::create([
            'account_id' => $this->account->id, 'project_id' => $project->id, 'client_id' => $this->client->id, 'task_id' => $next->id,
            'source' => TimeEntry::SOURCE_MANUAL, 'start_time' => now()->subMinutes(5),
        ]);
        $this->assertSame($running->id, $this->props('przeglad')['overview']['order_waiting']['task']['running_id']);

        $next->update(['finished_at' => now()]);
        $this->assertSame($later->id, $this->props('przeglad')['overview']['order_waiting']['task']['task_id']);

        $later->update(['finished_at' => now()]);
        $this->assertNull($this->props('przeglad')['overview']['order_waiting']['task']);
    }

    public function test_czeka_na_ciebie_names_an_unpaid_invoice_but_never_a_paid_or_draft_one(): void
    {
        config(['services.infakt.invoice_url' => 'https://app.infakt.pl/app/faktury/{id}']);
        $this->invoice('1/09/2026', 'paid', '2026-09-01');
        $this->invoice('2/09/2026', 'draft', '2026-09-28');
        $this->assertNull($this->props('przeglad')['overview']['order_waiting']['invoice']);

        $this->invoice('3/09/2026', 'sent', '2026-09-29', payment: '2026-10-14');
        $invoice = $this->props('przeglad')['overview']['order_waiting']['invoice'];
        $this->assertSame('3/09/2026', $invoice['number']);
        $this->assertSame('2026-10-14', $invoice['payment_date']);
        $this->assertFalse($invoice['overdue']);
        $this->assertSame('https://app.infakt.pl/app/faktury/ext-3/09/2026', $invoice['external_url']);

        $this->invoice('4/08/2026', 'sent', '2026-08-20', payment: '2026-09-03');
        $invoice = $this->props('przeglad')['overview']['order_waiting']['invoice'];
        $this->assertSame('4/08/2026', $invoice['number'], 'the most urgent unpaid invoice first');
        $this->assertTrue($invoice['overdue']);

        Invoice::query()->update(['status' => 'paid']);
        $this->assertNull($this->props('przeglad')['overview']['order_waiting']['invoice']);
    }

    public function test_a_retainer_client_has_no_order_waiting_rows(): void
    {
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'monthly_hours' => 10, 'effective_from' => '2026-01-01']);

        $this->assertNull($this->props('przeglad')['overview']['order_waiting']);
    }

    public function test_zadania_lists_an_orders_open_tasks_once_its_done_ones_apart_and_its_time(): void
    {
        $project = $this->client->generalProject();
        $board = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Board', 'trello_board_id' => 'b1']);
        $later = Task::create(['project_id' => $project->id, 'name' => 'Przekazanie raportu', 'source' => 'manual', 'list_name' => 'To-Do', 'due_date' => '2026-10-14']);
        $sooner = Task::create(['project_id' => $board->id, 'name' => 'Raport z audytu', 'source' => 'manual', 'list_name' => 'Doing', 'due_date' => '2026-10-09']);
        $undated = Task::create(['project_id' => $project->id, 'name' => 'Bez terminu', 'source' => 'manual', 'list_name' => 'Testing']);
        $ticked = Task::create(['project_id' => $project->id, 'name' => 'Pomiary', 'source' => 'manual', 'finished_at' => '2026-09-29 10:00:00']);
        $onDone = Task::create(['project_id' => $project->id, 'name' => 'Wizja lokalna', 'source' => 'manual', 'list_name' => TrelloListMapper::LANE_DONE, 'finished_at' => '2026-09-22 10:00:00']);
        $this->entry('2026-09-22 08:00:00', 150);
        TimeEntry::query()->latest('id')->first()->update(['task_id' => $onDone->id, 'title' => 'Wizja']);
        $this->entry('2026-09-29 08:00:00', 240);
        TimeEntry::query()->latest('id')->first()->update(['task_id' => $ticked->id]);
        $this->entry('2026-10-05 08:00:00', 90);

        $tasks = $this->props('zadania')['tasks'];

        $this->assertSame('order', $tasks['mode']);
        $this->assertSame(3, $tasks['open']['count']);
        $this->assertSame([$sooner->id, $later->id, $undated->id], array_column($tasks['open']['rows'], 'task_id'));
        $this->assertSame(2, $tasks['done']['count']);
        $this->assertSame([$ticked->id, $onDone->id], array_column($tasks['done']['rows'], 'task_id'));
        $this->assertSame(['2026-09-29', 240], [$tasks['done']['rows'][0]['done_on'], $tasks['done']['rows'][0]['minutes']]);
        $this->assertSame(8.0, $tasks['time']['hours']);
        $this->assertSame([90, 240, 150], array_column($tasks['time']['rows'], 'minutes'));
        $this->assertSame(['2026-10-05', 'Jan Przykładowy'], [$tasks['time']['rows'][0]['date'], $tasks['time']['rows'][0]['label']]);
        $this->assertSame('Pomiary', $tasks['time']['rows'][1]['label']);
        $this->assertArrayNotHasKey('lanes', $tasks);
    }

    public function test_an_orders_tab_badge_counts_its_whole_open_list_backlog_included(): void
    {
        $project = $this->client->generalProject();
        Task::create(['project_id' => $project->id, 'name' => 'Pomiary', 'source' => 'manual', 'list_name' => 'Doing']);
        Task::create(['project_id' => $project->id, 'name' => 'Pomysł', 'source' => 'manual', 'list_name' => 'Backlog']);
        Task::create(['project_id' => $project->id, 'name' => 'Zrobione', 'source' => 'manual', 'finished_at' => now()]);

        $props = $this->props('zadania');

        $this->assertSame(2, $props['tasks']['open']['count']);
        $this->assertSame($props['tasks']['open']['count'], $props['client']['open_tasks_count']);
    }

    public function test_zadania_bounds_an_orders_done_tasks_and_time_and_expands_on_request(): void
    {
        $project = $this->client->generalProject();
        foreach (range(1, 7) as $i) {
            Task::create(['project_id' => $project->id, 'name' => "Zrobione {$i}", 'source' => 'manual', 'finished_at' => now()->subDays($i)]);
            $this->entry(sprintf('2026-09-%02d 08:00:00', $i), 30);
        }

        $tasks = $this->props('zadania')['tasks'];
        $this->assertSame(7, $tasks['done']['count']);
        $this->assertCount(5, $tasks['done']['rows']);
        $this->assertCount(5, $tasks['time']['rows']);
        $this->assertSame(7, $tasks['time']['count']);

        $response = $this->actingAs($this->user)->get("/clients/{$this->client->id}/zadania?wszystkie=done");
        $this->assertCount(7, $response->viewData('page')['props']['tasks']['done']['rows']);
    }

    public function test_a_retainer_client_keeps_its_task_lanes(): void
    {
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'monthly_hours' => 10, 'effective_from' => '2026-01-01']);

        $tasks = $this->props('zadania')['tasks'];
        $this->assertSame('retainer', $tasks['mode']);
        $this->assertCount(3, $tasks['lanes']);
    }

    public function test_a_retainer_client_gets_no_order_block(): void
    {
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'monthly_hours' => 10, 'effective_from' => '2026-01-01']);

        $this->assertNull($this->props('przeglad')['overview']['order']);
        $this->assertSame(ClientBillingMode::RETAINER, $this->props('rozliczenia')['billing']['mode']);
        $this->assertArrayNotHasKey('order', $this->props('rozliczenia')['billing']);
        $this->assertSame(ClientBillingMode::RETAINER, $this->props('dane')['client']['billing_mode']);
    }

    public function test_dane_carries_the_individual_type(): void
    {
        $this->assertSame('individual', $this->props('dane')['details']['type']);
    }

    public function test_another_accounts_tasks_entries_invoices_and_retainers_never_reach_the_order_block(): void
    {
        $other = Account::create(['name' => 'Other']);
        $foreign = $other->clients()->create(['name' => 'Obcy', 'currency' => 'PLN']);
        $foreignProject = $foreign->generalProject();
        Task::create(['project_id' => $foreignProject->id, 'name' => 'Foreign', 'source' => 'manual']);
        TimeEntry::create([
            'account_id' => $other->id, 'project_id' => $foreignProject->id, 'client_id' => $foreign->id,
            'source' => TimeEntry::SOURCE_MANUAL, 'start_time' => '2026-09-01 08:00:00', 'end_time' => '2026-09-01 09:00:00', 'duration_minutes' => 60,
        ]);
        Invoice::create(['account_id' => $other->id, 'client_id' => $foreign->id, 'external_id' => 'x-1', 'number' => '9/09/2026', 'status' => 'paid', 'invoice_date' => '2026-09-05', 'gross_price' => 100]);
        ClientRetainer::create(['account_id' => $other->id, 'client_id' => $foreign->id, 'monthly_hours' => 9, 'effective_from' => '2026-01-01']);

        $order = $this->props('przeglad')['overview']['order'];

        $this->assertSame(['done' => 0, 'total' => 0], $order['tasks']);
        $this->assertSame(0.0, $order['hours']);
        $this->assertNull($order['invoice']);
        $this->assertSame(ClientBillingMode::ORDER, $this->mode());
        $tasks = $this->props('zadania')['tasks'];
        $this->assertSame([0, 0, 0], [$tasks['open']['count'], $tasks['done']['count'], $tasks['time']['count']]);
        $this->assertSame(['task' => null, 'invoice' => null], $this->props('przeglad')['overview']['order_waiting']);
        $this->actingAs($this->user)->get("/clients/{$foreign->id}/przeglad")->assertNotFound();
    }

    public function test_a_report_without_contracted_hours_never_becomes_bank_debt_and_the_brief_has_no_bank(): void
    {
        ClientReport::create([
            'account_id' => $this->account->id, 'client_id' => $this->client->id, 'period_type' => ClientReport::PERIOD_MONTH,
            'status' => ClientReport::STATUS_SENT, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'contracted_hours' => null, 'opening_balance_hours' => 0, 'actual_hours' => 2, 'composer_key' => 'manual',
        ]);

        $brief = app(ClientBrief::class)->for($this->client->fresh());
        $this->assertSame(ClientBillingMode::ORDER, $brief['billing_mode']);
        $this->assertNull($brief['hours']['bank']);

        $this->artisan('crm:brief', ['client' => (string) $this->client->id])
            ->expectsOutputToContain('Hours bank: not applicable')
            ->doesntExpectOutputToContain('used of')
            ->assertSuccessful();

        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'monthly_hours' => 10, 'effective_from' => '2026-10-01']);

        $october = app(HoursBank::class)->month($this->client, '2026-10');
        $this->assertSame(0.0, $october->openingBalanceHours);
        $this->assertSame(10.0, $october->availableHours());
        $this->assertSame(ClientBillingMode::RETAINER, app(ClientBrief::class)->for($this->client->fresh())['billing_mode']);
    }

    public function test_an_order_page_reads_the_layout_once_and_never_builds_the_hours_bank_however_many_reports(): void
    {
        $this->report('2026-08-01', '2026-08-31', null);
        $queries = fn (string $tab): array => $this->queriesFor("/clients/{$this->client->id}/{$tab}");

        $queries('przeglad');
        $overview = $queries('przeglad');
        $billing = $queries('rozliczenia');
        foreach (['2026-03', '2026-04', '2026-05', '2026-06', '2026-07'] as $month) {
            $this->report("{$month}-01", Carbon::parse("{$month}-01")->endOfMonth()->toDateString(), null);
        }

        foreach (['przeglad' => $overview, 'rozliczenia' => $billing] as $tab => $before) {
            $after = $queries($tab);
            $this->assertCount(count($before), $after, "{$tab} grows with the report history");
            $this->assertCount(1, array_filter($after, fn (string $sql): bool => str_contains($sql, 'client_month_closures')), "{$tab} reads closures beyond the layout check");
            $this->assertCount(1, array_filter($after, fn (string $sql): bool => str_contains($sql, 'client_retainers')), "{$tab} reads retainers beyond the layout check");
        }
    }

    /** @return list<string> */
    private function queriesFor(string $url): array
    {
        $this->actingAs($this->user);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        DB::disableQueryLog();

        return array_column(DB::getQueryLog(), 'query');
    }

    private function mode(): string
    {
        return app(ClientBillingMode::class)->of($this->client);
    }

    /** @return array<string, mixed> */
    private function props(string $tab, ?Client $client = null): array
    {
        $response = $this->actingAs($this->user)->get('/clients/'.($client ?? $this->client)->id.'/'.$tab);
        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    private function report(string $start, string $end, ?float $contracted): void
    {
        ClientReport::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'period_type' => ClientReport::PERIOD_MONTH,
            'status' => ClientReport::STATUS_DRAFT,
            'period_start' => $start,
            'period_end' => $end,
            'contracted_hours' => $contracted,
            'actual_hours' => 1,
            'composer_key' => 'manual',
        ]);
    }

    private function entry(string $start, int $minutes, ?Project $project = null): void
    {
        TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => ($project ?? $this->client->generalProject())->id,
            'client_id' => $this->client->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => $start,
            'end_time' => Carbon::parse($start)->addMinutes($minutes),
            'duration_minutes' => $minutes,
        ]);
    }

    private function invoice(string $number, string $status, string $issued, ?string $payment = null, int $gross = 240000, int $net = 195122): void
    {
        Invoice::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'external_id' => 'ext-'.$number,
            'number' => $number,
            'status' => $status,
            'currency' => 'PLN',
            'net_price' => $net,
            'gross_price' => $gross,
            'invoice_date' => $issued,
            'payment_date' => $payment,
        ]);
    }
}
