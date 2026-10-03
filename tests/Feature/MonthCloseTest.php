<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
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
                ->where('clients.1.template_total', count(MonthCloseRun::SITE_STEPS) + count(MonthCloseRun::CLIENT_STEPS))
                ->where('clients.0.template_total', count(MonthCloseRun::CLIENT_STEPS))
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

    public function test_period_list_is_floored_at_june_2026(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10'));

        // Newest first, down to June 2026 (the first close month) — never earlier.
        $this->actingAs($this->user)
            ->get('/month-close')
            ->assertInertia(fn (Assert $assert) => $assert
                ->where('periods', ['2026-09', '2026-08', '2026-07', '2026-06'])
            );
    }

    public function test_maintenance_close_seeds_the_full_checklist(): void
    {
        $this->actingAs($this->user)
            ->post('/month-close', ['client_id' => $this->site->id, 'period' => '2026-06'])
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
            ->post('/month-close', ['client_id' => $this->site->id, 'period' => '2026-06']);

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
            ->post('/month-close', ['client_id' => $this->site->id, 'period' => '2026-06']);

        $this->assertSame(
            array_keys(MonthCloseRun::CLIENT_STEPS),
            MonthCloseRun::firstOrFail()->steps()->pluck('step_key')->all(),
        );
    }

    public function test_gig_close_seeds_the_lean_checklist(): void
    {
        $this->actingAs($this->user)
            ->post('/month-close', ['client_id' => $this->agency->id, 'period' => '2026-06']);

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
            ->post('/month-close', ['client_id' => $this->site->id, 'period' => '2026-06']);
        $this->actingAs($this->user)
            ->post('/month-close', ['client_id' => $this->site->id, 'period' => '2026-06']);

        $this->assertSame(1, MonthCloseRun::where('client_id', $this->site->id)->count());
    }

    public function test_cannot_start_close_for_a_client_not_in_monthly_close(): void
    {
        $other = $this->user->account->clients()->create(['name' => 'Not Ours']);

        $this->actingAs($this->user)
            ->post('/month-close', ['client_id' => $other->id, 'period' => '2026-06'])
            ->assertNotFound();
    }

    public function test_toggling_all_steps_marks_the_run_completed(): void
    {
        $this->actingAs($this->user)
            ->post('/month-close', ['client_id' => $this->site->id, 'period' => '2026-06']);

        $run = MonthCloseRun::firstOrFail();

        foreach ($run->steps as $index => $step) {
            $state = $index % 2 === 0 ? MonthCloseStep::STATE_DONE : MonthCloseStep::STATE_SKIPPED;

            $this->actingAs($this->user)
                ->patch("/month-close/steps/{$step->id}", ['state' => $state])
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
            ->post('/month-close', ['client_id' => $this->site->id, 'period' => '2026-06']);

        $run = MonthCloseRun::firstOrFail();
        $run->steps->each(fn (MonthCloseStep $s) => $s->update(['state' => MonthCloseStep::STATE_DONE]));
        $run->refreshStatusFromSteps();
        $this->assertSame(MonthCloseRun::STATUS_COMPLETED, $run->fresh()->status);

        $step = $run->steps()->first();
        $this->actingAs($this->user)
            ->patch("/month-close/steps/{$step->id}", ['state' => MonthCloseStep::STATE_PENDING])
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
            ->post('/month-close', ['client_id' => $this->site->id, 'period' => '2026-06']);

        $keys = MonthCloseRun::firstOrFail()->steps()->pluck('step_key')->all();

        $this->assertContains(MonthCloseRun::REPORT_MODE_SUMMARY_EMAIL, $keys);
        $this->assertNotContains('report', $keys);
    }

    public function test_report_mode_none_drops_the_deliverable_step(): void
    {
        $this->site->update(['report_mode' => MonthCloseRun::REPORT_MODE_NONE]);

        $this->actingAs($this->user)
            ->post('/month-close', ['client_id' => $this->site->id, 'period' => '2026-06']);

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
            ->post('/month-close', ['client_id' => $this->site->id, 'period' => '2026-06']);
        $step = MonthCloseStep::firstOrFail();

        $intruder = User::factory()->create([
            'account_id' => Account::create(['name' => 'Rival'])->id,
            'owner' => true,
        ]);

        $this->actingAs($intruder)
            ->patch("/month-close/steps/{$step->id}", ['state' => MonthCloseStep::STATE_DONE])
            ->assertNotFound();
    }
}
