<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Tools\DayPlanTool;
use App\Mcp\Tools\DayUnplanTool;
use App\Mcp\Tools\TodayTool;
use App\Models\Account;
use App\Models\Client;
use App\Models\DayClose;
use App\Models\DayPick;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Concerns\CallsAgentSurfaces;
use Tests\Feature\Concerns\TimesOutThePlanLock;
use Tests\TestCase;

/**
 * Agents plan today and tomorrow through DayPlanner, under the day screen's
 * rules: Warsaw dates, at most five picks, a closed day left as it is.
 */
final class AgentDayPlanTest extends TestCase
{
    use CallsAgentSurfaces;
    use RefreshDatabase;
    use TimesOutThePlanLock;

    private Account $account;

    private User $owner;

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.display_timezone' => 'Europe/Warsaw']);
        Carbon::setTestNow('2026-10-07 10:00:00');

        $this->account = Account::factory()->create();
        config(['agent.local_account' => $this->account->id]);
        $this->owner = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $client = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Pracownia Wzorcowa']);
        $this->site = Project::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'name' => 'Site']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_plan_picks_a_task_for_today_by_name_and_returns_the_day(): void
    {
        $task = $this->task('Update plugins');

        [$exit, $out] = $this->verb('crm:plan', ['task' => 'plugins']);

        $this->assertSame(0, $exit);
        $this->assertSame($task->id, $out['task_id']);
        $this->assertSame(['2026-10-07', 'today', false, 5], [$out['plan']['date'], $out['plan']['day'], $out['plan']['closed'], $out['plan']['max_picks']]);
        $this->assertSame([$task->id], collect($out['plan']['picks'])->pluck('task_id')->all());
        $this->assertSame('2026-10-07', DayPick::query()->sole()->date->toDateString());
    }

    public function test_tomorrow_is_the_warsaw_date_after_midnight_there(): void
    {
        Carbon::setTestNow('2026-10-07 22:30:00');
        $task = $this->task('Late');

        [, $out] = $this->tool(DayPlanTool::class, ['task' => (string) $task->id, 'day' => 'tomorrow']);

        $this->assertSame('2026-10-09', $out['plan']['date']);
        [, $today] = $this->verb('crm:plan');
        $this->assertSame('2026-10-08', $today['plan']['date']);
    }

    public function test_a_sixth_pick_lands_and_the_plan_carries_a_warning(): void
    {
        foreach (range(1, 5) as $n) {
            [, $fifth] = $this->verb('crm:plan', ['task' => (string) $this->task("Task {$n}")->id, '--day' => 'tomorrow']);
        }
        $this->assertNull($fifth['plan']['warning']);

        [$exit, $out] = $this->verb('crm:plan', ['task' => (string) $this->task('Sixth')->id, '--day' => 'tomorrow']);

        $this->assertSame(0, $exit);
        $this->assertCount(6, $out['plan']['picks']);
        $this->assertSame('The day holds 6 picks, more than 5.', $out['plan']['warning']);
        $this->assertSame(6, DayPick::query()->count());
    }

    public function test_a_closed_day_takes_no_change_but_tomorrow_still_does(): void
    {
        $picked = $this->task('Picked');
        $this->verb('crm:plan', ['task' => (string) $picked->id]);
        DayClose::create(['account_id' => $this->account->id, 'date' => '2026-10-07', 'closed_at' => now()]);

        [, $add] = $this->tool(DayPlanTool::class, ['task' => (string) $this->task('Another')->id]);
        [, $remove] = $this->tool(DayUnplanTool::class, ['task' => (string) $picked->id]);
        [$ok] = $this->tool(DayPlanTool::class, ['task' => (string) $picked->id, 'day' => 'tomorrow']);

        $this->assertSame('invalid_argument', $add['error']);
        $this->assertSame('invalid_argument', $remove['error']);
        $this->assertTrue($ok);
        $this->assertSame(1, DayPick::query()->whereDate('date', '2026-10-07')->count());
    }

    public function test_a_plan_change_while_another_holds_the_plan_is_refused_to_be_sent_again(): void
    {
        $picked = $this->task('Picked');
        $this->verb('crm:plan', ['task' => (string) $picked->id]);
        $this->planLockTimesOut();

        [$exit, $plan] = $this->verb('crm:plan', ['task' => (string) $picked->id, '--day' => 'tomorrow']);
        [, $unplan] = $this->tool(DayUnplanTool::class, ['task' => (string) $picked->id]);

        $this->assertSame(1, $exit);
        foreach ([$plan, $unplan] as $refusal) {
            $this->assertSame(['refused', 'The plan is being changed. Try again.', true], [$refusal['error'], $refusal['message'], $refusal['retry']]);
        }
        $this->assertSame(['2026-10-07'], DayPick::query()->live()->get()->map(fn (DayPick $pick) => $pick->date->toDateString())->all());
    }

    public function test_plans_reach_from_today_to_the_end_of_next_week(): void
    {
        $task = $this->task('Later');

        $this->assertSame('invalid_argument', $this->verb('crm:plan', ['task' => (string) $task->id, '--day' => '2026-10-19'])[1]['error']);
        $this->assertSame('invalid_argument', $this->verb('crm:plan', ['--day' => '2026-10-06'])[1]['error']);
        [$exit, $out] = $this->verb('crm:plan', ['task' => (string) $task->id, '--day' => '2026-10-18']);
        $this->assertSame(0, $exit);
        $this->assertSame(['2026-10-18', '2026-10-18'], [$out['plan']['date'], $out['plan']['day']]);
    }

    public function test_unplan_removes_a_pick_moved_past_next_week(): void
    {
        $task = $this->task('Far');
        $this->actingAs($this->owner)->post('/day/picks', ['task_id' => $task->id, 'date' => '2026-10-07']);
        $this->actingAs($this->owner)->post('/day/picks', ['task_id' => $task->id, 'date' => '2026-11-20'])->assertSessionHasNoErrors();

        [$exit, $out] = $this->verb('crm:unplan', ['task' => (string) $task->id, '--day' => '2026-11-20']);

        $this->assertSame([0, $task->id], [$exit, $out['task_id']]);
        $this->assertSame(0, DayPick::live()->count());
        $this->assertSame('invalid_argument', $this->verb('crm:plan', ['task' => (string) $this->task('New')->id, '--day' => '2026-11-20'])[1]['error']);
    }

    public function test_only_open_tasks_of_the_acting_account_are_planned(): void
    {
        $done = $this->task('Finished', ['finished_at' => now(), 'is_completed' => true, 'list_name' => 'Done']);
        $stranger = Account::factory()->create();
        $foreign = Task::create(['project_id' => Project::create(['account_id' => $stranger->id, 'name' => 'X'])->id, 'name' => 'Secret', 'source' => 'manual', 'list_name' => 'To-Do']);

        $this->assertSame('invalid_argument', $this->verb('crm:plan', ['task' => (string) $done->id])[1]['error']);
        $this->assertSame('not_found', $this->verb('crm:plan', ['task' => 'Finished'])[1]['error']);
        $this->assertSame('not_found', $this->tool(DayPlanTool::class, ['task' => (string) $foreign->id])[1]['error']);
        $this->assertSame(0, DayPick::query()->count());
    }

    public function test_unplan_matches_a_fragment_among_the_days_picks_only(): void
    {
        $one = $this->task('Header fix');
        $two = $this->task('Header copy');
        $this->task('Header unpicked');
        $this->verb('crm:plan', ['task' => (string) $one->id]);
        $this->verb('crm:plan', ['task' => (string) $two->id]);

        [, $ambiguous] = $this->verb('crm:unplan', ['task' => 'Header']);
        $this->assertSame('ambiguous_reference', $ambiguous['error']);
        $this->assertSame([$one->id, $two->id], collect($ambiguous['candidates'])->pluck('id')->all());

        [$exit, $out] = $this->verb('crm:unplan', ['task' => 'copy']);
        $this->assertSame([0, $two->id], [$exit, $out['task_id']]);
        $this->assertSame([$one->id], DayPick::query()->pluck('task_id')->all());
        $this->assertSame('not_found', $this->verb('crm:unplan', ['task' => 'unpicked'])[1]['error']);
    }

    public function test_today_returns_both_plans_with_card_names_marked_untrusted(): void
    {
        $board = Project::create(['account_id' => $this->account->id, 'name' => 'Board', 'trello_board_id' => 'b1']);
        $card = Task::create(['project_id' => $board->id, 'name' => 'Card text', 'source' => 'trello', 'trello_card_id' => 'c1', 'list_name' => 'To-Do']);
        $manual = $this->task('Mine');
        $this->verb('crm:plan', ['task' => (string) $card->id]);
        $this->verb('crm:plan', ['task' => (string) $manual->id, '--day' => 'tomorrow']);

        [, $today] = $this->tool(TodayTool::class);

        $this->assertSame(['name', 'project'], $today['plan']['today']['picks'][0]['untrusted']);
        $this->assertSame([], $today['plan']['tomorrow']['picks'][0]['untrusted']);
        $this->assertSame('2026-10-08', $today['plan']['tomorrow']['date']);

        Artisan::call('crm:today');
        $this->assertMatchesRegularExpression('/BEGIN EXTERNAL CONTENT [0-9a-f]{8}.*Plan for today.*Card text.*Plan for tomorrow.*Mine.*END EXTERNAL CONTENT/s', Artisan::output());
    }

    public function test_card_names_stay_marked_in_an_ambiguous_unplan_and_a_board_name_in_a_manual_pick(): void
    {
        $board = Project::create(['account_id' => $this->account->id, 'name' => 'Board', 'trello_board_id' => 'b1']);
        foreach (['Card one', 'Card two'] as $n => $name) {
            $this->verb('crm:plan', ['task' => (string) Task::create(['project_id' => $board->id, 'name' => $name, 'source' => 'trello', 'trello_card_id' => "c{$n}", 'list_name' => 'To-Do'])->id]);
        }
        $manual = Task::create(['project_id' => $board->id, 'name' => 'Typed here', 'source' => 'manual', 'list_name' => 'To-Do']);

        [, $ambiguous] = $this->tool(DayUnplanTool::class, ['task' => 'Card']);
        [, $planned] = $this->tool(DayPlanTool::class, ['task' => (string) $manual->id]);

        $this->assertSame(['candidates'], $ambiguous['untrusted']);
        $this->assertSame(['project'], collect($planned['plan']['picks'])->firstWhere('task_id', $manual->id)['untrusted']);
    }

    public function test_the_day_screens_follow_the_same_closed_day_rule(): void
    {
        $task = $this->task('Picked');
        $this->actingAs($this->owner)->post('/day/picks', ['task_id' => $task->id, 'date' => '2026-10-07'])->assertSessionHasNoErrors();
        DayClose::create(['account_id' => $this->account->id, 'date' => '2026-10-07', 'closed_at' => now()]);

        $this->actingAs($this->owner)->post('/day/picks', ['task_id' => $this->task('Other')->id, 'date' => '2026-10-07'])->assertSessionHasErrors('task_id');
        $this->actingAs($this->owner)->delete('/day/picks/'.DayPick::query()->sole()->id)->assertSessionHasErrors('task_id');
        $this->actingAs($this->owner)->post('/day/picks', ['task_id' => $task->id, 'date' => '2026-10-08'])->assertSessionHasNoErrors();

        $this->assertSame(2, DayPick::query()->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(string $name, array $attributes = []): Task
    {
        return Task::create(['project_id' => $this->site->id, 'name' => $name, 'source' => 'manual', 'list_name' => 'To-Do', ...$attributes]);
    }
}
