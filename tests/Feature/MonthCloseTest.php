<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientMonthClosure;
use App\Models\ClientRetainer;
use App\Models\MonthCloseRun;
use App\Models\MonthCloseStep;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class MonthCloseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $site;

    private Client $agency;

    private Project $mainSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'account_id' => Account::create(['name' => 'Studio'])->id,
            'owner' => true,
        ]);

        // Maintenance site (abonament cohort) — full checklist.
        $this->site = $this->user->account->clients()->create([
            'name' => 'Roofs Ltd',
            'month_close_type' => MonthCloseRun::TYPE_MAINTENANCE,
            'include_in_month_close' => true,
        ]);

        // One site in the close. Clients here commonly run two or three, so the
        // multi-site cases below add more rather than assuming a single one.
        $this->mainSite = $this->site->projects()->create([
            'account_id' => $this->site->account_id,
            'name' => 'roofs.example',
            'include_in_month_close' => true,
        ]);

        // Agency monthly gig — lean checklist.
        $this->agency = $this->user->account->clients()->create([
            'name' => 'Agency X',
            'month_close_type' => MonthCloseRun::TYPE_GIG,
            'include_in_month_close' => true,
        ]);

        // Not in monthly close — must never appear on the worklist.
        $this->user->account->clients()->create(['name' => 'One-off Co']);
    }

    public function test_page_lists_both_cohorts_and_defaults_to_prior_month(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15'));

        $this->actingAs($this->user)
            ->get('/month-close')
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('month-close/index')
                ->where('period', '2026-06')
                ->has('clients', 2)
                // One site's steps plus the client-level tail.
                ->where('clients.1.total', count(MonthCloseRun::SITE_STEPS) + count(MonthCloseRun::CLIENT_STEPS))
                ->where('clients.0.total', count(MonthCloseRun::CLIENT_STEPS))
            );
    }

    public function test_the_default_period_is_the_local_month_just_ended(): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);
        // 00:30 on 1 August in Warsaw is still 31 July in UTC.
        $this->travelTo(Carbon::parse('2026-07-31 22:30:00', 'UTC'));

        $this->actingAs($this->user)
            ->get('/month-close')
            ->assertInertia(fn (Assert $assert) => $assert->where('period', '2026-07'));
    }

    public function test_excluded_client_leaves_the_worklist_but_keeps_its_type(): void
    {
        $this->site->update(['include_in_month_close' => false]);

        $this->actingAs($this->user)
            ->get('/month-close')
            ->assertInertia(fn (Assert $assert) => $assert->has('clients', 1));

        $this->assertSame(MonthCloseRun::TYPE_MAINTENANCE, $this->site->fresh()->month_close_type);
    }

    public function test_setting_a_type_includes_the_client_by_default(): void
    {
        $oneOff = $this->user->account->clients()->where('name', 'One-off Co')->firstOrFail();
        $this->assertFalse($oneOff->include_in_month_close);

        $this->actingAs($this->user)
            ->patch("/clients/{$oneOff->id}/month-close-type", ['month_close_type' => 'maintenance'])
            ->assertRedirect();

        $this->assertTrue($oneOff->fresh()->include_in_month_close);
    }

    public function test_respects_selected_period(): void
    {
        $this->actingAs($this->user)
            ->get('/month-close?period=2026-03')
            ->assertInertia(fn (Assert $assert) => $assert->where('period', '2026-03'));
    }

    public function test_the_trail_shows_the_months_around_the_period_within_june_2026_and_the_month_in_progress(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10'));

        foreach (['' => ['2026-07', '2026-08', '2026-09'], '?period=2026-09' => ['2026-07', '2026-08', '2026-09'], '?period=2026-06' => ['2026-06', '2026-07', '2026-08'], '?period=2026-03' => ['2026-06', '2026-07', '2026-08']] as $query => $months) {
            $this->actingAs($this->user)
                ->get('/month-close'.$query)
                ->assertInertia(fn (Assert $assert) => $assert->where('months', $months));
        }
    }

    public function test_maintenance_close_seeds_the_full_checklist(): void
    {
        $this->actingAs($this->user)
            ->patch("/month-close/{$this->site->id}/steps", ['period' => '2026-06', 'step' => 'reconcile_log', 'state' => MonthCloseStep::STATE_PENDING])
            ->assertRedirect();

        $run = MonthCloseRun::where('client_id', $this->site->id)->firstOrFail();

        $this->assertSame(MonthCloseRun::TYPE_MAINTENANCE, $run->close_type);
        $this->assertSame(
            [...array_keys(MonthCloseRun::SITE_STEPS), ...array_keys(MonthCloseRun::CLIENT_STEPS)],
            $run->steps()->pluck('step_key')->all(),
        );

        // Site steps carry their site; the client tail does not.
        $this->assertSame(
            $this->mainSite->id,
            $run->steps()->where('step_key', 'wp_updates')->value('project_id'),
        );
        $this->assertNull($run->steps()->where('step_key', 'draft_invoice')->value('project_id'));
    }

    public function test_each_site_gets_its_own_set_of_site_steps(): void
    {
        $second = $this->site->projects()->create([
            'account_id' => $this->site->account_id,
            'name' => 'shingles.example',
            'include_in_month_close' => true,
        ]);

        // Not in the close — an unreleased rebuild must not be seeded.
        $this->site->projects()->create([
            'account_id' => $this->site->account_id,
            'name' => 'roofs-rebuild',
        ]);

        $this->actingAs($this->user)
            ->patch("/month-close/{$this->site->id}/steps", ['period' => '2026-06', 'step' => 'reconcile_log', 'state' => MonthCloseStep::STATE_PENDING]);

        $run = MonthCloseRun::where('client_id', $this->site->id)->firstOrFail();

        $this->assertCount(
            2 * count(MonthCloseRun::SITE_STEPS) + count(MonthCloseRun::CLIENT_STEPS),
            $run->steps,
        );

        foreach ([$this->mainSite->id, $second->id] as $projectId) {
            $this->assertSame(
                array_keys(MonthCloseRun::SITE_STEPS),
                $run->steps()->where('project_id', $projectId)->orderBy('position')->pluck('step_key')->all(),
            );
        }
    }

    public function test_maintenance_client_with_no_flagged_site_seeds_only_client_steps(): void
    {
        $this->mainSite->update(['include_in_month_close' => false]);

        $this->actingAs($this->user)
            ->patch("/month-close/{$this->site->id}/steps", ['period' => '2026-06', 'step' => 'reconcile_log', 'state' => MonthCloseStep::STATE_PENDING]);

        $this->assertSame(
            array_keys(MonthCloseRun::CLIENT_STEPS),
            MonthCloseRun::firstOrFail()->steps()->pluck('step_key')->all(),
        );
    }

    public function test_gig_close_seeds_the_lean_checklist(): void
    {
        $this->actingAs($this->user)
            ->patch("/month-close/{$this->agency->id}/steps", ['period' => '2026-06', 'step' => 'reconcile_log', 'state' => MonthCloseStep::STATE_PENDING]);

        $run = MonthCloseRun::where('client_id', $this->agency->id)->firstOrFail();

        $this->assertSame(MonthCloseRun::TYPE_GIG, $run->close_type);
        $this->assertSame(
            array_keys(MonthCloseRun::CLIENT_STEPS),
            $run->steps()->pluck('step_key')->all(),
        );
    }

    public function test_start_is_idempotent(): void
    {
        $this->actingAs($this->user)
            ->patch("/month-close/{$this->site->id}/steps", ['period' => '2026-06', 'step' => 'reconcile_log', 'state' => MonthCloseStep::STATE_PENDING]);
        $this->actingAs($this->user)
            ->patch("/month-close/{$this->site->id}/steps", ['period' => '2026-06', 'step' => 'reconcile_log', 'state' => MonthCloseStep::STATE_PENDING]);

        $this->assertSame(1, MonthCloseRun::where('client_id', $this->site->id)->count());
    }

    public function test_cannot_start_close_for_a_client_not_in_monthly_close(): void
    {
        $other = $this->user->account->clients()->create(['name' => 'Not Ours']);

        $this->actingAs($this->user)
            ->patch("/month-close/{$other->id}/steps", ['period' => '2026-06', 'step' => 'reconcile_log', 'state' => MonthCloseStep::STATE_PENDING])
            ->assertSessionHasErrors('step');

        $this->assertSame(0, MonthCloseRun::count());
    }

    public function test_toggling_all_steps_marks_the_run_completed(): void
    {
        $this->actingAs($this->user)
            ->patch("/month-close/{$this->site->id}/steps", ['period' => '2026-06', 'step' => 'reconcile_log', 'state' => MonthCloseStep::STATE_PENDING]);

        $run = MonthCloseRun::firstOrFail();

        foreach ($run->steps as $index => $step) {
            $state = $index % 2 === 0 ? MonthCloseStep::STATE_DONE : MonthCloseStep::STATE_SKIPPED;

            $this->actingAs($this->user)
                ->patch("/month-close/{$this->site->id}/steps", ['period' => '2026-06', 'step' => $step->step_key, 'project_id' => $step->project_id, 'state' => $state])
                ->assertRedirect();
        }

        $run->refresh();
        $this->assertSame(MonthCloseRun::STATUS_COMPLETED, $run->status);
        $this->assertNotNull($run->completed_at);

        $first = $run->steps()->orderBy('position')->first();
        $this->assertSame(MonthCloseStep::STATE_DONE, $first->state);
        $this->assertNotNull($first->completed_at);
        $this->assertSame($this->user->id, $first->completed_by);
    }

    public function test_resetting_a_step_to_pending_reopens_the_run(): void
    {
        $this->actingAs($this->user)
            ->patch("/month-close/{$this->site->id}/steps", ['period' => '2026-06', 'step' => 'reconcile_log', 'state' => MonthCloseStep::STATE_PENDING]);

        $run = MonthCloseRun::firstOrFail();
        $run->steps->each(fn (MonthCloseStep $s) => $s->update(['state' => MonthCloseStep::STATE_DONE]));
        $run->refreshStatusFromSteps();
        $this->assertSame(MonthCloseRun::STATUS_COMPLETED, $run->fresh()->status);

        $step = $run->steps()->first();
        $this->actingAs($this->user)
            ->patch("/month-close/{$this->site->id}/steps", ['period' => '2026-06', 'step' => $step->step_key, 'project_id' => $step->project_id, 'state' => MonthCloseStep::STATE_PENDING])
            ->assertRedirect();

        $step->refresh();
        $this->assertNull($step->completed_at);
        $this->assertNull($step->completed_by);
        $this->assertSame(MonthCloseRun::STATUS_OPEN, $run->fresh()->status);
    }

    public function test_summary_email_client_gets_the_email_step_instead_of_report(): void
    {
        $this->site->update(['report_mode' => MonthCloseRun::REPORT_MODE_SUMMARY_EMAIL]);

        $this->actingAs($this->user)
            ->patch("/month-close/{$this->site->id}/steps", ['period' => '2026-06', 'step' => 'reconcile_log', 'state' => MonthCloseStep::STATE_PENDING]);

        $keys = MonthCloseRun::firstOrFail()->steps()->pluck('step_key')->all();

        $this->assertContains(MonthCloseRun::REPORT_MODE_SUMMARY_EMAIL, $keys);
        $this->assertNotContains('report', $keys);
    }

    public function test_report_mode_none_drops_the_deliverable_step(): void
    {
        $this->site->update(['report_mode' => MonthCloseRun::REPORT_MODE_NONE]);

        $this->actingAs($this->user)
            ->patch("/month-close/{$this->site->id}/steps", ['period' => '2026-06', 'step' => 'reconcile_log', 'state' => MonthCloseStep::STATE_PENDING]);

        $run = MonthCloseRun::firstOrFail();
        $keys = $run->steps()->pluck('step_key')->all();

        $this->assertNotContains('report', $keys);
        $this->assertNotContains('summary_email', $keys);
        $this->assertCount(count(MonthCloseRun::SITE_STEPS) + count(MonthCloseRun::CLIENT_STEPS) - 1, $keys);
    }

    public function test_report_mode_can_be_set(): void
    {
        $this->actingAs($this->user)
            ->patch("/clients/{$this->site->id}/report-mode", ['report_mode' => 'summary_email'])
            ->assertRedirect();

        $this->assertSame('summary_email', $this->site->fresh()->report_mode);
    }

    public function test_month_close_type_can_be_set_and_cleared(): void
    {
        $this->actingAs($this->user)
            ->patch("/clients/{$this->site->id}/month-close-type", ['month_close_type' => 'gig'])
            ->assertRedirect();
        $this->assertSame('gig', $this->site->fresh()->month_close_type);

        $this->actingAs($this->user)
            ->patch("/clients/{$this->site->id}/month-close-type", ['month_close_type' => null])
            ->assertRedirect();
        $this->assertNull($this->site->fresh()->month_close_type);
    }

    public function test_adding_a_retainer_defaults_the_client_into_maintenance(): void
    {
        $fresh = $this->user->account->clients()->create(['name' => 'New Abonament']);
        $this->assertNull($fresh->month_close_type);

        $this->actingAs($this->user)
            ->post("/clients/{$fresh->id}/retainers", [
                'monthly_hours' => 5,
                'monthly_fee' => 549,
                'effective_from' => '2026-06-01',
            ])
            ->assertRedirect();

        $this->assertSame(MonthCloseRun::TYPE_MAINTENANCE, $fresh->fresh()->month_close_type);
    }

    public function test_ssh_config_persists_on_the_client(): void
    {
        $ssh = 'deploy@roofs.example.com';

        $this->actingAs($this->user)
            ->put("/clients/{$this->site->id}", [
                'type' => 'business',
                'name' => $this->site->name,
                'ssh_config' => $ssh,
            ])
            ->assertRedirect();

        $this->assertSame($ssh, $this->site->fresh()->ssh_config);
    }

    public function test_a_site_carries_its_own_backup_folder(): void
    {
        // The vault does not name folders after CRM projects, so the destination
        // is stored per site rather than derived from the client's base.
        $this->actingAs($this->user)
            ->put("/projects/{$this->mainSite->id}", [
                'name' => $this->mainSite->name,
                'include_in_month_close' => true,
                'backup_path' => '/srv/backups/roofs/main-site',
            ])
            ->assertRedirect();

        $this->assertSame(
            '/srv/backups/roofs/main-site',
            $this->mainSite->fresh()->backup_path,
        );

        $this->artisan('client:config', ['client' => $this->site->id, '--json' => true])
            ->expectsOutputToContain('/srv/backups/roofs/main-site')
            ->assertSuccessful();
    }

    public function test_tick_command_starts_a_run_and_marks_a_step(): void
    {
        $this->artisan('month-close:tick', [
            'client' => $this->site->id,
            'step' => 'db_archived',
            'state' => 'done',
            '--period' => '2026-06',
        ])->assertSuccessful();

        $step = MonthCloseRun::where('client_id', $this->site->id)->firstOrFail()
            ->steps()->where('step_key', 'db_archived')->firstOrFail();

        $this->assertSame(MonthCloseStep::STATE_DONE, $step->state);
        $this->assertNotNull($step->completed_at);
    }

    public function test_a_mistyped_step_fails_without_starting_the_run(): void
    {
        $this->artisan('month-close:tick', [
            'client' => $this->site->id,
            'step' => 'db_archvied',
            'state' => 'done',
            '--period' => '2026-06',
        ])->expectsOutputToContain("Step 'db_archvied' is not in this run")->assertFailed();

        $this->assertSame(0, MonthCloseRun::where('client_id', $this->site->id)->count());
        $this->assertSame(0, MonthCloseStep::count());
    }

    public function test_an_unknown_site_fails_without_starting_the_run(): void
    {
        $this->artisan('month-close:tick', [
            'client' => $this->site->id,
            'step' => 'db_archived',
            'state' => 'done',
            '--project' => 'nowhere',
            '--period' => '2026-06',
        ])->assertFailed();

        $this->assertSame(0, MonthCloseRun::where('client_id', $this->site->id)->count());
    }

    public function test_tick_records_a_note_and_keeps_it_on_a_later_move(): void
    {
        $this->artisan('month-close:tick', [
            'client' => $this->site->id,
            'step' => 'commit_merge',
            'state' => 'skipped',
            '--note' => 'Composer-managed: plugins are gitignored, nothing to commit.',
            '--period' => '2026-07',
        ])->assertSuccessful();

        $step = fn () => MonthCloseRun::where('client_id', $this->site->id)->firstOrFail()
            ->steps()->where('step_key', 'commit_merge')->firstOrFail();

        $this->assertSame(MonthCloseStep::STATE_SKIPPED, $step()->state);
        $this->assertStringContainsString('Composer-managed', (string) $step()->note);

        // Re-ticking without a note must not erase the reason someone recorded.
        $this->artisan('month-close:tick', [
            'client' => $this->site->id,
            'step' => 'commit_merge',
            'state' => 'done',
            '--period' => '2026-07',
        ])->assertSuccessful();

        $this->assertSame(MonthCloseStep::STATE_DONE, $step()->state);
        $this->assertStringContainsString('Composer-managed', (string) $step()->note);
    }

    public function test_tick_refuses_an_ambiguous_step_and_names_the_sites(): void
    {
        $this->site->projects()->create([
            'account_id' => $this->site->account_id,
            'name' => 'shingles.example',
            'include_in_month_close' => true,
        ]);

        $this->artisan('month-close:tick', [
            'client' => $this->site->id,
            'step' => 'wp_updates',
            'state' => 'done',
            '--period' => '2026-06',
        ])
            ->expectsOutputToContain('shingles.example')
            ->assertFailed();

        // Nothing moved: an ambiguous reference must not guess a site.
        $this->assertSame(
            0,
            MonthCloseStep::where('step_key', 'wp_updates')->where('state', MonthCloseStep::STATE_DONE)->count(),
        );
    }

    public function test_tick_with_a_site_moves_only_that_sites_step(): void
    {
        $second = $this->site->projects()->create([
            'account_id' => $this->site->account_id,
            'name' => 'shingles.example',
            'include_in_month_close' => true,
        ]);

        $this->artisan('month-close:tick', [
            'client' => $this->site->id,
            'step' => 'wp_updates',
            'state' => 'done',
            '--project' => 'shingles',
            '--period' => '2026-06',
        ])->assertSuccessful();

        $run = MonthCloseRun::where('client_id', $this->site->id)->firstOrFail();

        $this->assertSame(
            MonthCloseStep::STATE_DONE,
            $run->steps()->where('step_key', 'wp_updates')->where('project_id', $second->id)->value('state'),
        );
        $this->assertSame(
            MonthCloseStep::STATE_PENDING,
            $run->steps()->where('step_key', 'wp_updates')->where('project_id', $this->mainSite->id)->value('state'),
        );
    }

    public function test_tick_command_refuses_a_client_not_in_monthly_close(): void
    {
        $other = $this->user->account->clients()->create(['name' => 'Not Ours']);

        $this->artisan('month-close:tick', ['client' => $other->id, 'step' => 'list'])
            ->assertFailed();
    }

    public function test_config_command_outputs_the_cohort(): void
    {
        $this->artisan('client:config', ['client' => $this->site->id, '--json' => true])
            ->expectsOutputToContain('maintenance')
            ->assertSuccessful();
    }

    public function test_steps_are_account_scoped(): void
    {
        $this->actingAs($this->user)
            ->patch("/month-close/{$this->site->id}/steps", ['period' => '2026-06', 'step' => 'reconcile_log', 'state' => MonthCloseStep::STATE_PENDING]);
        $step = MonthCloseStep::firstOrFail();

        $intruder = User::factory()->create([
            'account_id' => Account::create(['name' => 'Rival'])->id,
            'owner' => true,
        ]);

        $this->actingAs($intruder)
            ->patch("/month-close/{$this->site->id}/steps", ['period' => '2026-06', 'step' => 'reconcile_log', 'state' => MonthCloseStep::STATE_DONE])
            ->assertNotFound();
        $this->actingAs($intruder)->get("/month-close/{$this->site->id}?period=2026-06")->assertNotFound();
        $this->assertSame(MonthCloseStep::STATE_PENDING, $step->fresh()->state);
    }

    public function test_a_client_still_owing_the_hours_month_is_listed_without_a_checklist(): void
    {
        // 00:30 on 1 October in Warsaw: September has just ended there while UTC is still in it.
        config(['app.display_timezone' => 'Europe/Warsaw']);
        $this->travelTo(Carbon::parse('2026-09-30 22:30:00', 'UTC'));
        $account = $this->user->account;
        $account->update(['settings' => ['settlement_from' => '2026-09']]);
        $owing = $account->clients()->create(['name' => 'Owing Retainer']);
        ClientRetainer::create(['account_id' => $account->id, 'client_id' => $owing->id, 'monthly_hours' => 10, 'effective_from' => '2026-01-01']);

        $this->actingAs($this->user)
            ->get('/month-close?period=2026-09')
            ->assertInertia(fn (Assert $assert) => $assert
                ->has('clients', 3)
                ->where('clients.0.client.name', 'Agency X')
                ->where('clients.0.total', count(MonthCloseRun::CLIENT_STEPS))
                ->where('clients.1.client.name', 'Owing Retainer')
                ->where('clients.1.total', 0)
                ->where('clients.1.next', null)
                ->where('clients.1.settlement.state', 'ready')
                ->where('clients.2.client.name', 'Roofs Ltd'));

        $this->actingAs($this->user)
            ->patch("/month-close/{$owing->id}/steps", ['period' => '2026-09', 'step' => 'reconcile_log', 'state' => MonthCloseStep::STATE_DONE])
            ->assertSessionHasErrors('step');
    }

    public function test_only_clients_owing_that_very_month_join_the_list(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'UTC'));
        $account = $this->user->account;
        $account->update(['settings' => ['settlement_from' => '2026-08']]);
        $retainer = fn (Client $client, ?string $to = null) => ClientRetainer::create(['account_id' => $client->account_id, 'client_id' => $client->id, 'monthly_hours' => 10, 'effective_from' => '2026-01-01', 'effective_to' => $to]);

        $august = $account->clients()->create(['name' => 'Still Owes August']);
        $retainer($august);
        $closed = $account->clients()->create(['name' => 'Closed September']);
        $retainer($closed);
        foreach (['2026-08', '2026-09'] as $period) {
            ClientMonthClosure::create(['client_id' => $closed->id, 'period' => $period, 'closed_at' => now(), 'snapshot' => ['closing' => 0.0, 'used' => 10.0, 'contracted' => 10.0, 'opening' => 0.0, 'cap' => null]]);
        }
        $ended = $account->clients()->create(['name' => 'Retainer Ended']);
        $retainer($ended, '2026-08-01');
        $archived = $account->clients()->create(['name' => 'Archived Owing']);
        $retainer($archived);
        ClientMonthClosure::create(['client_id' => $archived->id, 'period' => '2026-08', 'closed_at' => now(), 'snapshot' => ['closing' => 0.0, 'used' => 10.0, 'contracted' => 10.0, 'opening' => 0.0, 'cap' => null]]);
        $archived->delete();
        $other = Account::create(['name' => 'Other', 'settings' => ['settlement_from' => '2026-08']]);
        $foreign = $other->clients()->create(['name' => 'Foreign Owing']);
        $retainer($foreign);
        ClientMonthClosure::create(['client_id' => $foreign->id, 'period' => '2026-08', 'closed_at' => now(), 'snapshot' => ['closing' => 0.0, 'used' => 10.0, 'contracted' => 10.0, 'opening' => 0.0, 'cap' => null]]);

        $names = fn (string $period) => collect($this->actingAs($this->user)->get("/month-close?period={$period}")->viewData('page')['props']['clients'])->pluck('client.name')->all();

        $this->assertSame(['Agency X', 'Roofs Ltd'], $names('2026-09'));
        $this->assertSame(['Agency X', 'Roofs Ltd', 'Still Owes August'], $names('2026-08'));
    }

    public function test_month_close_lists_exactly_the_clients_the_klienci_note_counts(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'UTC'));
        $account = $this->user->account;
        $account->update(['settings' => ['settlement_from' => '2026-08']]);
        $close = fn (Client $client, string $period) => ClientMonthClosure::create(['client_id' => $client->id, 'period' => $period, 'closed_at' => now(), 'snapshot' => ['closing' => 0.0, 'used' => 10.0, 'contracted' => 10.0, 'opening' => 0.0, 'cap' => null]]);
        $retainer = fn (Client $client, ?string $to = null) => ClientRetainer::create(['account_id' => $client->account_id, 'client_id' => $client->id, 'monthly_hours' => 10, 'effective_from' => '2026-01-01', 'effective_to' => $to]);

        // The retainer ended with August, which is still owed.
        $ended = $account->clients()->create(['name' => 'Ended Retainer']);
        $retainer($ended, '2026-09-01');
        // September was closed, then reopened, and its retainer removed after.
        $reopened = $account->clients()->create(['name' => 'Reopened September']);
        $reopenedRetainer = $retainer($reopened);
        $close($reopened, '2026-08');
        $september = $close($reopened, '2026-09');
        $this->actingAs($this->user)
            ->delete("/clients/{$reopened->id}/months/2026-09/close", ['closure_id' => $september->id])
            ->assertSessionHas('success');
        $reopenedRetainer->delete();
        // A maintenance client taken out of the close, still owing September.
        $excluded = $account->clients()->create(['name' => 'Taken Out', 'month_close_type' => MonthCloseRun::TYPE_MAINTENANCE, 'include_in_month_close' => false]);
        $retainer($excluded);
        $close($excluded, '2026-08');

        $owing = function (string $period): array {
            $rows = collect($this->actingAs($this->user)->get("/month-close?period={$period}")->viewData('page')['props']['clients']);

            return $rows->filter(fn (array $row): bool => ($row['settlement']['state'] ?? null) === 'ready')
                ->map(fn (array $row): array => [$row['client']['name'], $row['total']])
                ->values()
                ->all();
        };

        $this->assertSame([['Ended Retainer', 0]], $owing('2026-08'));
        $this->assertSame([['Reopened September', 0], ['Taken Out', 0]], $owing('2026-09'));

        $this->actingAs($this->user)
            ->get('/clients')
            ->assertInertia(fn (Assert $page) => $page->where('open_months', ['count' => 3, 'period' => null, 'oldest' => '2026-08']));
    }

    public function test_a_step_remembers_whether_the_app_or_an_agent_moved_it(): void
    {
        $this->actingAs($this->user)
            ->patch("/month-close/{$this->site->id}/steps", ['period' => '2026-06', 'step' => 'reconcile_log', 'state' => MonthCloseStep::STATE_DONE])
            ->assertSessionHasNoErrors();
        $this->artisan('month-close:tick', ['client' => $this->site->id, 'step' => 'report', 'state' => 'done', '--period' => '2026-06'])->assertSuccessful();

        $steps = MonthCloseStep::whereNull('project_id')->pluck('completed_via', 'step_key');
        $this->assertSame(MonthCloseStep::VIA_WEB, $steps['reconcile_log']);
        $this->assertSame(MonthCloseStep::VIA_AGENT, $steps['report']);
        $this->assertNull($steps['draft_invoice']);
    }

    public function test_a_client_row_names_the_next_step_and_who_does_it(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15'));
        $tick = fn (string $step) => $this->actingAs($this->user)->patch("/month-close/{$this->site->id}/steps", ['period' => '2026-06', 'step' => $step, 'state' => MonthCloseStep::STATE_DONE]);

        $this->actingAs($this->user)->get('/month-close')->assertInertia(fn (Assert $assert) => $assert
            ->where('clients.1.client.name', 'Roofs Ltd')
            ->where('clients.1.resolved', 0)
            ->where('clients.1.next', ['key' => 'db_archived', 'owner' => 'agent']));

        foreach (['db_archived', 'local_db_import', 'wp_updates', 'local_verify', 'commit_merge'] as $step) {
            $tick($step);
        }

        $this->actingAs($this->user)->get('/month-close')->assertInertia(fn (Assert $assert) => $assert
            ->where('clients.1.resolved', 5)
            ->where('clients.1.next', ['key' => 'live_deploy', 'owner' => 'you'])
            ->where('clients.1.finished_on', null));
    }

    public function test_a_client_with_every_step_moved_is_finished_on_the_day_it_completed(): void
    {
        $this->travelTo(Carbon::parse('2026-07-03 10:00'));
        foreach (array_keys(MonthCloseRun::CLIENT_STEPS) as $step) {
            $this->actingAs($this->user)->patch("/month-close/{$this->agency->id}/steps", ['period' => '2026-06', 'step' => $step, 'state' => MonthCloseStep::STATE_SKIPPED]);
        }

        $this->actingAs($this->user)->get('/month-close')->assertInertia(fn (Assert $assert) => $assert
            ->where('clients.0.client.name', 'Agency X')
            ->where('clients.0.next', null)
            ->where('clients.0.finished_on', '2026-07-03'));
    }

    public function test_the_client_card_shows_the_steps_per_site_then_the_clients_own_without_starting_the_close(): void
    {
        $this->actingAs($this->user)
            ->get("/month-close/{$this->site->id}?period=2026-06")
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('month-close/show')
                ->where('client.name', 'Roofs Ltd')
                ->has('sites', 1)
                ->where('sites.0.project_id', $this->mainSite->id)
                ->has('sites.0.steps', count(MonthCloseRun::SITE_STEPS))
                ->where('sites.0.steps.5', fn ($step) => $step['key'] === 'live_deploy' && $step['owner'] === 'you' && $step['state'] === 'pending')
                ->has('client_steps', count(MonthCloseRun::CLIENT_STEPS)));

        $this->assertSame(0, MonthCloseRun::count());
    }

    public function test_a_client_outside_the_close_has_no_card(): void
    {
        $other = $this->user->account->clients()->create(['name' => 'Not Ours']);

        $this->actingAs($this->user)->get("/month-close/{$other->id}?period=2026-06")->assertNotFound();
    }
}
