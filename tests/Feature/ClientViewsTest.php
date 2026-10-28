<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientLifecycleEvent;
use App\Models\ClientView;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Agent\AgentAbilities;
use App\Services\Clients\ClientViews;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Inertia\Support\Header;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class ClientViewsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $user;

    private Client $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.display_timezone' => 'Europe/Warsaw']);
        $this->travelTo(Carbon::parse('2026-01-10 10:00:00', 'UTC'));

        $this->account = Account::create(['name' => 'Acme']);
        $this->user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->client = $this->account->clients()->create(['name' => 'Pracownia Testowa', 'currency' => 'PLN']);
        $this->project = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Site']);

        $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
    }

    public function test_the_table_keeps_one_row_per_user_and_client_and_goes_with_the_client(): void
    {
        $this->assertTrue(Schema::hasColumns('client_views', ['id', 'user_id', 'client_id', 'viewed_at', 'created_at', 'updated_at']));

        ClientView::create(['user_id' => $this->user->id, 'client_id' => $this->client->id, 'viewed_at' => now()]);
        try {
            ClientView::create(['user_id' => $this->user->id, 'client_id' => $this->client->id, 'viewed_at' => now()]);
            $this->fail('A second row for the same user and client was stored.');
        } catch (QueryException) {
            $this->assertSame(1, ClientView::query()->count());
        }

        $this->client->forceDelete();
        $this->assertSame(0, ClientView::query()->count());
    }

    public function test_a_first_visit_counts_from_local_midnight_seven_days_back_and_records_the_view(): void
    {
        $this->entry('2026-09-27 21:30:00');
        $this->entry('2026-09-27 22:30:00');

        $this->assertSame(['since' => '2026-09-28', 'since_today' => false, 'count' => 1], $this->overview()['journal']);
        $this->assertSame('2026-10-05 10:00:00', $this->viewedAt($this->user));
    }

    public function test_the_next_visit_counts_from_the_one_before(): void
    {
        $this->overview();
        $this->entry('2026-10-05 09:00:00');
        $this->entry('2026-10-05 11:00:00');

        $this->travelTo(Carbon::parse('2026-10-06 08:00:00', 'UTC'));
        $this->event('2026-10-06 07:00:00');

        $this->assertSame(['since' => '2026-10-05', 'since_today' => false, 'count' => 2], $this->overview()['journal']);
        $this->assertSame('2026-10-06 08:00:00', $this->viewedAt($this->user));

        $this->travelTo(Carbon::parse('2026-10-06 12:00:00', 'UTC'));
        $this->assertSame(['since' => '2026-10-06', 'since_today' => true, 'count' => 0], $this->overview()['journal']);
    }

    public function test_tabs_opened_during_one_visit_keep_where_new_starts(): void
    {
        $this->overview();
        $this->travelTo(Carbon::parse('2026-10-08 08:00:00', 'UTC'));
        $this->entry('2026-10-07 09:00:00');

        $this->assertSame(1, $this->overview()['journal']['count']);
        $this->travelTo(Carbon::parse('2026-10-08 08:10:00', 'UTC'));
        $this->actingAs($this->user)->get("/clients/{$this->client->id}/zadania")->assertOk();
        $this->travelTo(Carbon::parse('2026-10-08 08:20:00', 'UTC'));

        $this->assertSame(['since' => '2026-10-05', 'since_today' => false, 'count' => 1], $this->overview()['journal']);
        $this->assertSame('2026-10-08 08:20:00', $this->viewedAt($this->user));
    }

    public function test_a_visit_after_the_gap_starts_from_the_last_view(): void
    {
        $this->overview();
        $this->travelTo(Carbon::parse('2026-10-05 10:20:00', 'UTC'));
        $this->overview();
        $this->travelTo(Carbon::parse('2026-10-05 10:20:00', 'UTC')->addMinutes(ClientViews::VISIT_GAP_MINUTES + 1));
        $this->entry('2026-10-05 10:30:00');

        $journal = $this->overview()['journal'];
        $this->assertSame(1, $journal['count']);
        $this->assertSame('2026-10-05', $journal['since']);
    }

    public function test_a_view_row_the_visit_did_not_write_starts_a_new_visit(): void
    {
        $this->overview();
        $this->travelTo(Carbon::parse('2026-10-05 10:10:00', 'UTC'));
        ClientView::query()->update(['viewed_at' => '2026-10-05 10:05:00']);
        $this->entry('2026-10-05 10:02:00');
        $this->entry('2026-10-05 10:07:00');

        $this->assertSame(1, $this->overview()['journal']['count']);
    }

    public function test_two_requests_of_one_visit_both_keep_its_start_whatever_session_copy_they_carry(): void
    {
        $this->entry('2026-10-01 08:00:00');
        $this->overview();
        $stale = session()->all();

        $this->travelTo(Carbon::parse('2026-10-05 10:05:00', 'UTC'));
        $this->assertSame(1, $this->overview()['journal']['count']);

        $this->travelTo(Carbon::parse('2026-10-05 10:05:01', 'UTC'));
        $response = $this->actingAs($this->user)->withSession($stale)->get("/clients/{$this->client->id}/przeglad");

        $this->assertSame(1, $response->viewData('page')['props']['overview']['journal']['count']);
        $this->assertSame('2026-10-05 10:05:01', $this->viewedAt($this->user));
    }

    public function test_a_view_never_moves_back_in_time(): void
    {
        ClientView::create(['user_id' => $this->user->id, 'client_id' => $this->client->id, 'viewed_at' => '2026-10-05 10:03:00']);

        $this->overview();

        $this->assertSame('2026-10-05 10:03:00', $this->viewedAt($this->user));
    }

    public function test_a_view_still_being_recorded_elsewhere_leaves_this_request_reading_only(): void
    {
        $lock = Cache::lock("client_views.{$this->user->id}.{$this->client->id}.lock", 10);
        $this->assertTrue($lock->get());

        Sleep::fake(syncWithCarbon: true);

        $this->assertSame('2026-09-28', $this->overview()['journal']['since']);

        $this->assertSame(0, ClientView::query()->count());
        $lock->release();
    }

    public function test_a_request_whose_lease_expired_cannot_undo_the_one_that_took_over(): void
    {
        $this->overview();
        $this->travelTo(Carbon::parse('2026-10-05 10:10:00', 'UTC'));
        $views = app(ClientViews::class);
        $key = "client_views.{$this->user->id}.{$this->client->id}";
        $tookOver = false;

        DB::listen(function (QueryExecuted $query) use (&$tookOver, $views, $key): void {
            if ($tookOver || ! str_contains($query->sql, 'client_views') || ! str_starts_with(mb_strtolower($query->sql), 'select')) {
                return;
            }
            $tookOver = true;
            Cache::lock($key.'.lock')->forceRelease();
            $this->travelTo(Carbon::parse('2026-10-05 10:10:07', 'UTC'));
            $views->open($this->user, $this->client);
        });

        $views->open($this->user, $this->client);

        $this->assertTrue($tookOver);
        $this->assertSame('2026-10-05 10:10:07', $this->viewedAt($this->user));
        $this->assertSame(Carbon::parse('2026-10-05 10:10:07', 'UTC')->getTimestamp(), Cache::get($key)['seen']);
    }

    public function test_views_are_per_user_and_leave_other_users_alone(): void
    {
        $colleague = User::factory()->create(['account_id' => $this->account->id]);
        $this->overview();
        $this->travelTo(Carbon::parse('2026-10-06 08:00:00', 'UTC'));
        $this->entry('2026-10-01 08:00:00');

        $response = $this->actingAs($colleague)->get("/clients/{$this->client->id}/przeglad");

        $this->assertSame(['since' => '2026-09-29', 'since_today' => false, 'count' => 1], $response->viewData('page')['props']['overview']['journal']);
        $this->assertSame('2026-10-05 10:00:00', $this->viewedAt($this->user));
        $this->assertSame('2026-10-06 08:00:00', $this->viewedAt($colleague));
    }

    public function test_a_partial_reload_reads_but_records_nothing(): void
    {
        $this->actingAs($this->user)
            ->withHeaders([
                Header::INERTIA => 'true',
                Header::VERSION => (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
                Header::PARTIAL_COMPONENT => 'clients/show',
                Header::PARTIAL_ONLY => 'tasks',
            ])
            ->get("/clients/{$this->client->id}/zadania")
            ->assertOk();

        $this->assertSame(0, ClientView::query()->count());
    }

    public function test_a_hover_prefetch_records_nothing_and_the_click_records_the_visit(): void
    {
        $this->entry('2026-10-01 08:00:00');
        $inertia = [Header::INERTIA => 'true', Header::VERSION => (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request())];

        $prefetch = $this->actingAs($this->user)->withHeaders([...$inertia, 'Purpose' => 'prefetch'])->get("/clients/{$this->client->id}/przeglad");
        $prefetch->assertOk();
        $this->assertSame(1, $prefetch->json('props.overview.journal.count'));
        $this->assertSame(0, ClientView::query()->count());

        $click = $this->flushHeaders()->actingAs($this->user)->withHeaders($inertia)->get("/clients/{$this->client->id}/przeglad");
        $this->assertSame(1, $click->json('props.overview.journal.count'));
        $this->assertSame('2026-10-05 10:00:00', $this->viewedAt($this->user));

        $this->flushHeaders()->travelTo(Carbon::parse('2026-10-06 09:00:00', 'UTC'));
        $this->assertSame(0, $this->overview()['journal']['count']);
    }

    public function test_agents_and_the_agent_context_never_record_a_view(): void
    {
        $this->artisan('crm:brief', ['client' => (string) $this->client->id, '--json' => true])->assertSuccessful();
        $this->artisan('crm:note', ['client' => (string) $this->client->id, 'note' => 'Called the client'])->assertSuccessful();
        $this->actingAs($this->user)->getJson("/clients/{$this->client->id}/agent-context")->assertOk();

        $token = $this->user->createToken('agent', [AgentAbilities::READ], now()->addDay());
        $token->accessToken->forceFill(['account_id' => $this->account->id])->save();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken])
            ->post('/agent/verb', ['verb' => 'brief', 'args' => [(string) $this->client->id, '--json']])
            ->assertOk();

        $this->assertSame(0, ClientView::query()->count());
    }

    public function test_another_accounts_client_gets_no_view_row(): void
    {
        $other = Account::create(['name' => 'Other']);
        $stranger = $other->clients()->create(['name' => 'Pracownia Testowa', 'currency' => 'PLN']);

        foreach (['przeglad', 'dziennik', 'zadania'] as $tab) {
            $this->actingAs($this->user)->get("/clients/{$stranger->id}/{$tab}")->assertNotFound();
        }
        $this->assertSame(0, ClientView::query()->count());

        $this->expectException(NotFoundHttpException::class);
        app(ClientViews::class)->open($this->user, $stranger);
    }

    /**
     * @return array<string, mixed>
     */
    private function overview(): array
    {
        $response = $this->actingAs($this->user)->get("/clients/{$this->client->id}/przeglad");
        $response->assertOk();

        return $response->viewData('page')['props']['overview'];
    }

    private function viewedAt(User $user): ?string
    {
        $view = ClientView::query()->where('user_id', $user->id)->where('client_id', $this->client->id)->first();

        return $view?->viewed_at->format('Y-m-d H:i:s');
    }

    private function entry(string $startUtc): TimeEntry
    {
        $start = Carbon::parse($startUtc, 'UTC');

        return TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => $start,
            'end_time' => $start->copy()->addMinutes(30),
            'duration_minutes' => 30,
        ]);
    }

    private function event(string $atUtc): ClientLifecycleEvent
    {
        return ClientLifecycleEvent::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'user_id' => $this->user->id,
            'from_stage' => 'active',
            'to_stage' => 'active',
            'note' => 'Call about the shop',
            'created_at' => Carbon::parse($atUtc, 'UTC'),
        ]);
    }
}
