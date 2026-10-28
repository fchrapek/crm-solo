<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientMonthClosure;
use App\Models\ClientReport;
use App\Models\ClientRetainer;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Clients\ClientListQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ClientListTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.display_timezone' => 'Europe/Warsaw']);
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'UTC'));

        $this->account = Account::create(['name' => 'Acme']);
        $this->user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
    }

    public function test_the_list_renders_this_months_hours_for_each_client(): void
    {
        $retainer = $this->client('Studio Przykładowe sp. z o.o.', cooperation: 'retainer');
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $retainer->id, 'monthly_hours' => 10, 'effective_from' => '2026-01-01']);
        $this->entry($retainer, '2026-10-05 08:00:00', 14 * 60, reportable: true);
        $this->entry($retainer, '2026-09-28 08:00:00', 600, reportable: true);
        $hourly = $this->client('Kawiarnia Testowa', cooperation: 'hourly');
        $this->entry($hourly, '2026-10-06 08:00:00', 90, reportable: false);

        $this->actingAs($this->user)
            ->get('/clients')
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/index')
                ->where('filters', ['stage' => 'active', 'cooperation_type' => null, 'search' => ''])
                ->where('period', '2026-10')
                ->has('clients.data', 2)
                ->where('clients.data.0', [
                    'id' => $retainer->id,
                    'name' => 'Studio Przykładowe sp. z o.o.',
                    'short_name' => 'Studio Przykładowe',
                    'lifecycle_stage' => 'active',
                    'cooperation_type' => 'retainer',
                    'is_archived' => false,
                    'is_pinned' => false,
                    'agent_live' => false,
                    'hours' => ['period' => '2026-10', 'behind' => false, 'has_retainer' => true, 'used' => 14, 'available' => 10, 'remaining' => -4, 'logged' => 14],
                ])
                ->where('clients.data.1.name', 'Kawiarnia Testowa')
                ->where('clients.data.1.hours', ['period' => '2026-10', 'behind' => false, 'has_retainer' => false, 'used' => 0, 'available' => 0, 'remaining' => 0, 'logged' => 1.5])
                ->where('clients.total', 2)
            );
    }

    public function test_stage_tabs_count_every_stage_and_the_archive(): void
    {
        $this->client('Aktywny Przykładowy');
        $this->client('Drugi Przykładowy');
        $this->client('Wstrzymany Przykładowy', stage: 'paused');
        $this->client('Były Przykładowy', stage: 'churned');
        $this->client('Zarchiwizowany Przykładowy')->delete();

        $this->actingAs($this->user)
            ->get('/clients')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stage_counts', ['active' => 2, 'paused' => 1, 'churned' => 1, 'archived' => 1])
                ->has('clients.data', 2)
            );

        $this->actingAs($this->user)
            ->get('/clients?stage=archived')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.stage', 'archived')
                ->has('clients.data', 1)
                ->where('clients.data.0.name', 'Zarchiwizowany Przykładowy')
                ->where('clients.data.0.is_archived', true)
            );

        $this->actingAs($this->user)
            ->get('/clients?stage=churned')
            ->assertInertia(fn (Assert $page) => $page->has('clients.data', 1)->where('clients.data.0.name', 'Były Przykładowy'));
    }

    public function test_an_unknown_stage_or_cooperation_type_falls_back_to_the_defaults(): void
    {
        $this->client('Aktywny Przykładowy', cooperation: 'project');

        $this->actingAs($this->user)
            ->get('/clients?stage=all&cooperation_type=barter')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.stage', 'active')
                ->where('filters.cooperation_type', null)
                ->has('clients.data', 1)
            );
    }

    public function test_array_query_parameters_fall_back_to_the_defaults_instead_of_failing(): void
    {
        $this->client('Aktywny Przykładowy');

        $this->actingAs($this->user)
            ->get('/clients?search[]=x&stage[]=paused&cooperation_type[]=retainer&page[]=2')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters', ['stage' => 'active', 'cooperation_type' => null, 'search' => ''])
                ->has('clients.data', 1)
            );
    }

    public function test_the_cooperation_filter_narrows_the_list_and_each_facet_counts_with_the_other(): void
    {
        $this->client('Abonament A Przykładowy', cooperation: 'retainer');
        $this->client('Abonament B Przykładowy', cooperation: 'retainer');
        $this->client('Godzinowy Przykładowy', cooperation: 'hourly');
        $this->client('Bez typu Przykładowy');
        $this->client('Abonament wstrzymany Przykładowy', stage: 'paused', cooperation: 'retainer');

        $this->actingAs($this->user)
            ->get('/clients?cooperation_type=retainer')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.cooperation_type', 'retainer')
                ->has('clients.data', 2)
                ->where('clients.data.0.name', 'Abonament A Przykładowy')
                ->where('clients.data.1.name', 'Abonament B Przykładowy')
                ->where('cooperation_counts', ['all' => 4, 'retainer' => 2, 'hourly' => 1, 'project' => 0, 'one_off' => 0, 'none' => 1])
                ->where('stage_counts', ['active' => 2, 'paused' => 1, 'churned' => 0, 'archived' => 0])
            );

        $this->actingAs($this->user)
            ->get('/clients?stage=paused')
            ->assertInertia(fn (Assert $page) => $page
                ->where('cooperation_counts', ['all' => 1, 'retainer' => 1, 'hourly' => 0, 'project' => 0, 'one_off' => 0, 'none' => 0])
                ->where('stage_counts', ['active' => 4, 'paused' => 1, 'churned' => 0, 'archived' => 0])
            );
    }

    public function test_untyped_clients_have_their_own_filter_so_wszyscy_is_the_sum_of_the_rows(): void
    {
        $this->client('Abonament Przykładowy', cooperation: 'retainer');
        $this->client('Bez typu A Przykładowy');
        $this->client('Bez typu B Przykładowy');
        $this->client('Bez typu wstrzymany Przykładowy', stage: 'paused');

        $this->actingAs($this->user)
            ->get('/clients')
            ->assertInertia(function (Assert $page) {
                $counts = $page->toArray()['props']['cooperation_counts'];
                $this->assertSame(['all' => 3, 'retainer' => 1, 'hourly' => 0, 'project' => 0, 'one_off' => 0, 'none' => 2], $counts);
                $this->assertSame($counts['all'], array_sum($counts) - $counts['all']);
            });

        $this->actingAs($this->user)
            ->get('/clients?cooperation_type=none')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.cooperation_type', 'none')
                ->has('clients.data', 2)
                ->where('clients.data.0.name', 'Bez typu A Przykładowy')
                ->where('clients.data.1.name', 'Bez typu B Przykładowy')
                ->where('stage_counts', ['active' => 2, 'paused' => 1, 'churned' => 0, 'archived' => 0])
            );
    }

    public function test_a_phone_gets_the_same_props_from_the_same_queries_as_a_desktop(): void
    {
        $client = $this->client('Pracownia Przykładowa', cooperation: 'retainer');
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'monthly_hours' => 10, 'effective_from' => '2026-01-01']);
        $this->entry($client, '2026-10-05 08:00:00', 90, reportable: true);
        $this->client('Kawiarnia Przykładowa', cooperation: 'hourly');

        $visit = function (string $agent): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $props = $this->actingAs($this->user)->withHeader('User-Agent', $agent)->get('/clients')->viewData('page')['props'];
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$props, $queries];
        };

        $visit('warm-up');
        [$phone, $phoneQueries] = $visit('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148');
        [$desktop, $desktopQueries] = $visit('Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Version/18.0 Safari/605.1.15');

        $this->assertSame($desktopQueries, $phoneQueries);
        $this->assertSame(
            collect($desktop)->only(['filters', 'period', 'open_months', 'stage_counts', 'cooperation_counts', 'clients'])->all(),
            collect($phone)->only(['filters', 'period', 'open_months', 'stage_counts', 'cooperation_counts', 'clients'])->all(),
        );
    }

    public function test_search_matches_names_and_leaves_the_counts_alone(): void
    {
        $this->client('Piekarnia Przykładowa');
        $this->client('Kwiaciarnia Testowa');

        $this->actingAs($this->user)
            ->get('/clients?search=piekar')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', 'piekar')
                ->has('clients.data', 1)
                ->where('clients.data.0.name', 'Piekarnia Przykładowa')
                ->where('stage_counts.active', 2)
                ->where('cooperation_counts.all', 2)
            );
    }

    public function test_ten_clients_a_page(): void
    {
        foreach (range(1, 12) as $i) {
            $this->client(sprintf('Klient %02d Przykładowy', $i));
        }

        $this->actingAs($this->user)
            ->get('/clients')
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients.data', 10)
                ->where('clients.from', 1)
                ->where('clients.to', 10)
                ->where('clients.total', 12)
                ->where('clients.data.0.name', 'Klient 01 Przykładowy')
            );

        $this->actingAs($this->user)
            ->get('/clients?page=2&cooperation_type=')
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients.data', 2)
                ->where('clients.from', 11)
                ->where('clients.data.1.name', 'Klient 12 Przykładowy')
            );
    }

    public function test_page_links_keep_every_filter_and_equal_rows_split_by_id_across_the_boundary(): void
    {
        $ids = collect(range(1, 12))->map(fn (): int => $this->client('Klient Przykładowy', stage: 'paused', cooperation: 'retainer')->id);
        $this->client('Inny Przykładowy', stage: 'paused', cooperation: 'retainer');

        $query = 'stage=paused&cooperation_type=retainer&search=Klient';
        $this->actingAs($this->user)
            ->get("/clients?{$query}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('clients.total', 12)
                ->where('clients.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === $ids->take(10)->all())
                ->where('clients.links', fn ($links): bool => collect($links)->pluck('url')->filter()->every(
                    fn (string $url): bool => str_contains($url, 'stage=paused') && str_contains($url, 'cooperation_type=retainer') && str_contains($url, 'search=Klient'),
                ))
            );

        $this->actingAs($this->user)
            ->get("/clients?{$query}&page=2")
            ->assertInertia(fn (Assert $page) => $page
                ->where('clients.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === $ids->slice(10)->values()->all())
            );
    }

    public function test_a_running_agent_comes_first_then_this_months_hours(): void
    {
        $quiet = $this->client('A Cichy Przykładowy');
        $busy = $this->client('B Zajęty Przykładowy');
        $this->entry($busy, '2026-10-10 08:00:00', 300, reportable: false);
        $person = $this->client('C Osoba Przykładowa');
        $this->running($person, via: null);
        $agent = $this->client('D Agent Przykładowy');
        $this->running($agent, via: 'mcp');
        $this->entry($quiet, '2026-09-10 08:00:00', 900, reportable: false);

        $this->actingAs($this->user)
            ->get('/clients')
            ->assertInertia(fn (Assert $page) => $page
                ->where('clients.data.0.name', 'D Agent Przykładowy')
                ->where('clients.data.0.agent_live', true)
                ->where('clients.data.1.name', 'B Zajęty Przykładowy')
                ->where('clients.data.1.agent_live', false)
                ->where('clients.data.2.name', 'A Cichy Przykładowy')
                ->where('clients.data.3.name', 'C Osoba Przykładowa')
                ->where('clients.data.3.agent_live', false)
            );
    }

    public function test_pinned_clients_come_first_and_keep_the_usual_order_among_themselves_across_pages(): void
    {
        foreach (range(1, 11) as $i) {
            $this->client(sprintf('Klient %02d Przykładowy', $i));
        }
        $agent = $this->client('Agent Przykładowy');
        $this->running($agent, via: 'mcp');
        $busy = $this->client('Zajęty Przykładowy');
        $this->entry($busy, '2026-10-10 08:00:00', 300, reportable: false);
        $pinnedQuiet = $this->client('Zz Przypięty Przykładowy');
        $pinnedBusy = $this->client('Zy Przypięty Przykładowy');
        $this->entry($pinnedBusy, '2026-10-11 08:00:00', 60, reportable: false);
        $pinnedQuiet->update(['is_pinned' => true]);
        $pinnedBusy->update(['is_pinned' => true]);
        $this->client('Wstrzymany Przypięty', stage: 'paused')->update(['is_pinned' => true]);

        $this->actingAs($this->user)
            ->get('/clients')
            ->assertInertia(fn (Assert $page) => $page
                ->where('clients.total', 15)
                ->where('stage_counts', ['active' => 15, 'paused' => 1, 'churned' => 0, 'archived' => 0])
                ->where('clients.data', fn ($rows): bool => collect($rows)->pluck('name')->all() === [
                    'Zy Przypięty Przykładowy',
                    'Zz Przypięty Przykładowy',
                    'Agent Przykładowy',
                    'Zajęty Przykładowy',
                    ...collect(range(1, 6))->map(fn (int $i): string => sprintf('Klient %02d Przykładowy', $i))->all(),
                ])
                ->where('clients.data.0.is_pinned', true)
                ->where('clients.data.1.is_pinned', true)
                ->where('clients.data.2.is_pinned', false)
            );

        $this->actingAs($this->user)
            ->get('/clients?page=2')
            ->assertInertia(fn (Assert $page) => $page
                ->where('clients.from', 11)
                ->where('clients.data', fn ($rows): bool => collect($rows)->pluck('name')->all()
                    === collect(range(7, 11))->map(fn (int $i): string => sprintf('Klient %02d Przykładowy', $i))->all())
            );
    }

    public function test_another_accounts_clients_never_appear_or_count(): void
    {
        $this->client('Nasz Przykładowy', cooperation: 'retainer');
        $other = Account::create(['name' => 'Other']);
        $stranger = $other->clients()->create(['name' => 'Obcy Przykładowy', 'cooperation_type' => 'retainer', 'currency' => 'PLN']);
        $other->clients()->create(['name' => 'Obcy wstrzymany Przykładowy', 'lifecycle_stage' => 'paused', 'currency' => 'PLN']);
        $other->clients()->create(['name' => 'Obcy archiwalny Przykładowy', 'currency' => 'PLN'])->delete();
        $this->running($stranger, via: 'mcp');

        $this->actingAs($this->user)
            ->get('/clients?search=Przyk')
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients.data', 1)
                ->where('clients.data.0.name', 'Nasz Przykładowy')
                ->where('stage_counts', ['active' => 1, 'paused' => 0, 'churned' => 0, 'archived' => 0])
                ->where('cooperation_counts.all', 1)
                ->where('cooperation_counts.retainer', 1)
            );

        $this->actingAs($this->user)
            ->get('/clients?stage=archived')
            ->assertInertia(fn (Assert $page) => $page->has('clients.data', 0));
    }

    public function test_a_page_of_clients_costs_the_same_queries_as_one_client(): void
    {
        $measure = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(ClientListQuery::class)->props($this->account->id, []);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $first = $this->client('Klient 00 Przykładowy', cooperation: 'retainer');
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $first->id, 'monthly_hours' => 5, 'effective_from' => '2026-01-01']);
        $this->entry($first, '2026-10-05 08:00:00', 60, reportable: true);
        $one = $measure();

        foreach (range(1, 14) as $i) {
            $client = $this->client(sprintf('Klient %02d Przykładowy', $i), cooperation: 'retainer');
            ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'monthly_hours' => $i, 'effective_from' => '2026-01-01']);
            $this->entry($client, '2026-10-05 08:00:00', 30 * $i, reportable: true);
            $this->running($client, via: $i % 2 === 0 ? 'cli' : null);
        }

        $this->assertSame($one, $measure());
        $this->assertSame(20, $one, 'whether the account has its own fields, stage counts, type counts, page total, page rows, the book\'s settlement (account ids plus five) and nine for the hours bank');
    }

    public function test_rows_behind_and_rows_on_the_running_month_cost_queries_per_month_not_per_client(): void
    {
        $this->account->update(['settings' => ['settlement_from' => '2026-09']]);
        $measure = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(ClientListQuery::class)->props($this->account->id, []);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $add = function (int $i, bool $closed): void {
            $client = $this->client(sprintf('Klient %02d Przykładowy', $i), cooperation: 'retainer');
            ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'monthly_hours' => 10, 'effective_from' => '2026-01-01']);
            $this->entry($client, '2026-09-10 08:00:00', 60 * $i, reportable: true);
            if ($closed) {
                ClientMonthClosure::create(['client_id' => $client->id, 'period' => '2026-09', 'closed_at' => now(), 'snapshot' => ['closing' => 4.0, 'used' => 6.0, 'contracted' => 10.0, 'opening' => 0.0, 'cap' => null]]);
            }
        };

        $add(0, closed: false);
        $add(1, closed: true);
        $two = $measure();
        foreach (range(2, 9) as $i) {
            $add($i, closed: $i % 3 === 0);
        }

        $this->assertSame($two, $measure());
    }

    public function test_each_row_reads_its_active_month_and_the_note_counts_the_book_behind(): void
    {
        $this->account->update(['settings' => ['settlement_from' => '2026-09']]);
        $open = $this->client('Otwarty Przykładowy', cooperation: 'retainer');
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $open->id, 'monthly_hours' => 10, 'effective_from' => '2026-01-01']);
        $this->entry($open, '2026-09-10 08:00:00', 12 * 60, reportable: true);
        $this->entry($open, '2026-10-02 08:00:00', 60, reportable: true);
        $closed = $this->client('Zamknięty Przykładowy', cooperation: 'retainer');
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $closed->id, 'monthly_hours' => 10, 'effective_from' => '2026-01-01']);
        ClientMonthClosure::create(['client_id' => $closed->id, 'period' => '2026-09', 'closed_at' => now(), 'snapshot' => ['closing' => 4.0, 'used' => 6.0, 'contracted' => 10.0, 'opening' => 0.0, 'cap' => null]]);
        $paused = $this->client('Wstrzymany Przykładowy', stage: 'paused', cooperation: 'retainer');
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $paused->id, 'monthly_hours' => 5, 'effective_from' => '2026-01-01']);
        $archived = $this->client('Zarchiwizowany Przykładowy', cooperation: 'retainer');
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $archived->id, 'monthly_hours' => 5, 'effective_from' => '2026-01-01']);
        $archived->delete();
        $this->client('Bez Abonamentu Przykładowy', cooperation: 'hourly');

        $this->actingAs($this->user)
            ->get('/clients')
            ->assertInertia(fn (Assert $page) => $page
                ->where('open_months', ['count' => 2, 'period' => '2026-09', 'oldest' => '2026-09'])
                ->where('clients.data.0.name', 'Otwarty Przykładowy')
                ->where('clients.data.0.hours.period', '2026-09')
                ->where('clients.data.0.hours.behind', true)
                ->where('clients.data.0.hours.used', 12)
                ->where('clients.data.2.name', 'Zamknięty Przykładowy')
                ->where('clients.data.2.hours.period', '2026-10')
                ->where('clients.data.2.hours.behind', false)
                ->where('clients.data.2.hours.available', 14)
            );

        $this->actingAs($this->user)
            ->get('/clients?stage=archived')
            ->assertInertia(fn (Assert $page) => $page
                ->where('open_months.count', 2)
                ->where('clients.data.0.hours.period', '2026-09')
                ->where('clients.data.0.hours.behind', true));
    }

    public function test_the_note_names_no_month_when_the_clients_behind_owe_different_ones_and_links_the_oldest(): void
    {
        $this->account->update(['settings' => ['settlement_from' => '2026-08']]);
        $august = $this->client('Sierpień Przykładowy', cooperation: 'retainer');
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $august->id, 'monthly_hours' => 10, 'effective_from' => '2026-01-01']);
        $september = $this->client('Wrzesień Przykładowy', cooperation: 'retainer');
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $september->id, 'monthly_hours' => 10, 'effective_from' => '2026-01-01']);
        ClientMonthClosure::create(['client_id' => $september->id, 'period' => '2026-08', 'closed_at' => now(), 'snapshot' => ['closing' => 0.0, 'used' => 10.0, 'contracted' => 10.0, 'opening' => 0.0, 'cap' => null]]);

        $this->actingAs($this->user)
            ->get('/clients')
            ->assertInertia(fn (Assert $page) => $page->where('open_months', ['count' => 2, 'period' => null, 'oldest' => '2026-08']));
    }

    public function test_another_accounts_clients_behind_never_reach_the_note(): void
    {
        $this->account->update(['settings' => ['settlement_from' => '2026-09']]);
        $other = Account::create(['name' => 'Other', 'settings' => ['settlement_from' => '2026-09']]);
        $foreign = $other->clients()->create(['name' => 'Obcy Przykładowy']);
        ClientRetainer::create(['account_id' => $other->id, 'client_id' => $foreign->id, 'monthly_hours' => 10, 'effective_from' => '2026-01-01']);

        $this->actingAs($this->user)
            ->get('/clients')
            ->assertInertia(fn (Assert $page) => $page->where('open_months', ['count' => 0, 'period' => null, 'oldest' => null])->has('clients.data', 0));
    }

    public function test_a_one_off_client_reads_plain_logged_hours_with_no_pool(): void
    {
        $oneOff = $this->client('Jan Przykładowy', cooperation: 'one_off');
        ClientReport::create([
            'account_id' => $this->account->id, 'client_id' => $oneOff->id, 'period_type' => ClientReport::PERIOD_MONTH, 'status' => ClientReport::STATUS_SENT,
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'contracted_hours' => null, 'actual_hours' => 2, 'composer_key' => 'manual',
        ]);
        $this->entry($oneOff, '2026-10-06 08:00:00', 390, reportable: true);

        $this->actingAs($this->user)
            ->get('/clients')
            ->assertInertia(fn (Assert $page) => $page
                ->where('clients.data.0.hours.has_retainer', false)
                ->where('clients.data.0.hours.behind', false)
                ->where('clients.data.0.hours.logged', 6.5));
    }

    private function client(string $name, string $stage = 'active', ?string $cooperation = null): Client
    {
        return $this->account->clients()->create([
            'name' => $name,
            'lifecycle_stage' => $stage,
            'cooperation_type' => $cooperation,
            'currency' => 'PLN',
        ]);
    }

    private function entry(Client $client, string $startUtc, int $minutes, bool $reportable): TimeEntry
    {
        $task = Task::create(['project_id' => $this->project($client)->id, 'name' => 'Praca', 'is_reportable' => $reportable]);
        $start = Carbon::parse($startUtc, 'UTC');

        return TimeEntry::create([
            'account_id' => $client->account_id,
            'project_id' => $task->project_id,
            'client_id' => $client->id,
            'task_id' => $task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => $start,
            'end_time' => $start->copy()->addMinutes($minutes),
            'duration_minutes' => $minutes,
        ]);
    }

    private function running(Client $client, ?string $via): TimeEntry
    {
        $entry = TimeEntry::create([
            'account_id' => $client->account_id,
            'project_id' => $this->project($client)->id,
            'client_id' => $client->id,
            'source' => TimeEntry::SOURCE_TERMINAL_SESSION,
            'start_time' => now()->subMinutes(20),
        ]);
        $entry->forceFill(['actor_via' => $via])->save();

        return $entry;
    }

    private function project(Client $client): Project
    {
        return $client->projects()->firstOrFail();
    }
}
