<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\LogTimeTool;
use App\Mcp\Tools\TimerStartTool;
use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Agent\AmbiguousReferenceException;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Starting work resolves a name against open tasks; correcting past time
 * resolves against every task, open ones first. Candidate lists come back
 * in a stable order and say when they were cut.
 */
final class TaskNameResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-16 08:00:00', 'UTC'));
        $this->account = Account::create(['name' => 'Acc']);
        User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'ACME', 'type' => 'business']);
        $this->project = Project::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'name' => 'Site']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_starting_work_picks_the_open_task_over_a_finished_namesake(): void
    {
        $this->task('Header fix', finished: true);
        $open = $this->task('Header fix v2');

        $this->artisan('crm:timer-start', ['task' => 'Header fix'])->assertSuccessful();

        $this->assertSame($open->id, TimeEntry::sole()->task_id);
    }

    public function test_starting_work_on_a_name_only_finished_tasks_match_says_so(): void
    {
        $finished = $this->task('Footer fix', finished: true);

        $this->artisan('crm:timer-start', ['task' => 'Footer'])
            ->expectsOutputToContain('Only finished or archived tasks match')
            ->expectsOutputToContain("#{$finished->id} Footer fix (Site, finished)")
            ->assertFailed();

        $this->assertSame(0, TimeEntry::count());
    }

    public function test_the_mcp_timer_lists_the_finished_near_misses(): void
    {
        $finished = $this->task('Footer fix', finished: true);

        CrmServer::tool(TimerStartTool::class, ['task' => 'Footer'])
            ->assertHasErrors(['"error":"not_found"', '"near_misses":[{"id":'.$finished->id]);
    }

    public function test_logging_past_time_still_finds_a_finished_task(): void
    {
        $finished = $this->task('Footer fix', finished: true);

        CrmServer::tool(LogTimeTool::class, ['minutes' => 30, 'task' => 'Footer'])->assertOk();

        $this->assertSame($finished->id, TimeEntry::sole()->task_id);
    }

    public function test_candidates_list_open_tasks_first_then_the_most_recently_touched(): void
    {
        $oldFinished = $this->task('Menu A', finished: true, touched: '2026-09-01');
        $newFinished = $this->task('Menu B', finished: true, touched: '2026-09-15');
        $oldOpen = $this->task('Menu C', touched: '2026-09-02');
        $newOpen = $this->task('Menu D', touched: '2026-09-10');

        try {
            app(CrmEntityResolver::class)->task('Menu', $this->account->id);
            $this->fail('Several matches resolved to one task.');
        } catch (AmbiguousReferenceException $e) {
            $this->assertSame([$newOpen->id, $oldOpen->id, $newFinished->id, $oldFinished->id], array_column($e->candidates, 'id'));
            $this->assertSame(4, $e->total);
            $this->assertStringNotContainsString('Showing', $e->getMessage());
        }
    }

    public function test_a_cut_candidate_list_says_how_many_matched(): void
    {
        foreach (range(1, 8) as $n) {
            $this->task("Banner {$n}");
        }

        try {
            app(CrmEntityResolver::class)->task('Banner', $this->account->id, openOnly: true);
            $this->fail('Several matches resolved to one task.');
        } catch (AmbiguousReferenceException $e) {
            $this->assertCount(6, $e->candidates);
            $this->assertSame(8, $e->total);
            $this->assertStringContainsString('Showing 6 of 8 matches', $e->getMessage());
            $this->assertStringContainsString('... and 2 more', $e->candidateLines());
            $this->assertSame(8, $e->toPayload()['total']);
        }
    }

    public function test_a_task_finished_between_the_count_and_the_fetch_is_a_reference_error(): void
    {
        $task = $this->task('Sidebar fix');
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired, $task): void {
            if (! $fired && str_contains(mb_strtolower($query->sql), 'count(*)')) {
                $fired = true;
                Task::whereKey($task->id)->update(['finished_at' => now()]);
            }
        });

        try {
            app(CrmEntityResolver::class)->task('Sidebar', $this->account->id, openOnly: true);
            $this->fail('A finished task resolved as open.');
        } catch (ReferenceNotFoundException $e) {
            $this->assertSame([$task->id], array_column($e->nearMisses, 'id'));
        }
    }

    public function test_task_done_on_a_finished_task_says_nothing_changed(): void
    {
        $finished = $this->task('Footer fix', finished: true);

        $this->artisan('crm:task-done', ['task' => (string) $finished->id])
            ->expectsOutputToContain('Already finished')
            ->assertSuccessful();
    }

    public function test_a_finished_only_match_without_open_only_is_not_a_near_miss(): void
    {
        $this->expectException(ReferenceNotFoundException::class);

        app(CrmEntityResolver::class)->task('Nothing like this', $this->account->id);
    }

    private function task(string $name, bool $finished = false, ?string $touched = null): Task
    {
        $task = Task::create([
            'project_id' => $this->project->id, 'name' => $name, 'source' => 'manual',
            'is_completed' => $finished, 'list_name' => $finished ? 'Done' : 'To-Do',
            'finished_at' => $finished ? now()->subDay() : null,
        ]);

        if ($touched !== null) {
            Task::whereKey($task->id)->update(['updated_at' => Carbon::parse($touched)]);
        }

        return $task->fresh();
    }
}
