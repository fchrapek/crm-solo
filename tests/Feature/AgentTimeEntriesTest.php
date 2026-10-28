<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\LogTimeTool;
use App\Mcp\Tools\TimeEditTool;
use App\Mcp\Tools\TimeEntriesTool;
use App\Mcp\Tools\TimeSplitTool;
use App\Models\Account;
use App\Models\AgentAuditEvent;
use App\Models\Client;
use App\Models\ClientMonthClosure;
use App\Models\ClientReport;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Concerns\CallsAgentSurfaces;
use Tests\TestCase;

/**
 * Agents list, correct and split time entries through the time edit dialog's
 * writer, inside the acting account, never inside a settled period.
 */
final class AgentTimeEntriesTest extends TestCase
{
    use CallsAgentSurfaces;
    use RefreshDatabase;

    private Account $account;

    private Client $client;

    private Project $site;

    private Task $catchAll;

    private Task $header;

    private Task $form;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.display_timezone' => 'Europe/Warsaw']);
        Carbon::setTestNow('2026-10-07 10:00:00');

        $this->account = Account::factory()->create();
        config(['agent.local_account' => $this->account->id]);
        User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->client = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Studio Fikcja']);
        $this->site = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Website']);
        $this->catchAll = $this->task('Catch-all session');
        $this->header = $this->task('Header redesign');
        $this->form = $this->task('Contact form');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_entry_counts_on_the_warsaw_day_it_started_and_shows_local_times(): void
    {
        $afterMidnight = $this->entry($this->header, '2026-10-06 22:30:00', 60);
        $beforeMidnight = $this->entry($this->form, '2026-10-06 21:30:00', 30);

        [$exit, $today] = $this->verb('crm:time-entries', ['--day' => 'today']);
        [, $yesterday] = $this->tool(TimeEntriesTool::class, ['day' => 'yesterday']);

        $this->assertSame(0, $exit);
        $this->assertSame([$afterMidnight->id], array_column($today['entries'], 'id'));
        $this->assertSame(['2026-10-07 00:30', '2026-10-07 01:30', 60], [$today['entries'][0]['start'], $today['entries'][0]['end'], $today['entries'][0]['minutes']]);
        $this->assertSame('Europe/Warsaw', $today['timezone']);
        $this->assertSame([$beforeMidnight->id], array_column($yesterday['entries'], 'id'));
        $this->assertSame('2026-10-06 23:30', $yesterday['entries'][0]['start']);
    }

    public function test_a_range_is_inclusive_newest_first_and_counts_before_the_limit(): void
    {
        $first = $this->entry($this->header, '2026-10-01 08:00:00', 30);
        $second = $this->entry($this->form, '2026-10-03 08:00:00', 45);
        $this->entry($this->form, '2026-10-04 08:00:00', 15);

        [, $out] = $this->verb('crm:time-entries', ['--from' => '2026-10-01', '--to' => '2026-10-03', '--limit' => '1']);

        $this->assertSame([2, 75, '2026-10-01', '2026-10-03'], [$out['total'], $out['total_minutes'], $out['from'], $out['to']]);
        $this->assertSame([$second->id], array_column($out['entries'], 'id'));
        $this->assertNotSame($first->id, $out['entries'][0]['id']);
        $this->assertSame('invalid_argument', $this->verb('crm:time-entries', ['--day' => 'today', '--from' => '2026-10-01'])[1]['error']);
        $this->assertSame('invalid_argument', $this->verb('crm:time-entries', ['--from' => '2026-10-05', '--to' => '2026-10-01'])[1]['error']);
    }

    public function test_running_lists_only_open_timers_with_the_minutes_so_far(): void
    {
        $this->entry($this->form, '2026-10-07 07:00:00', 30);
        $running = TimeEntry::startFor($this->header);
        Carbon::setTestNow('2026-10-07 11:00:00');

        [, $out] = $this->tool(TimeEntriesTool::class, ['running' => true]);

        $this->assertSame([$running->id], array_column($out['entries'], 'id'));
        $this->assertSame([true, null, 60, 60], [$out['entries'][0]['running'], $out['entries'][0]['end'], $out['entries'][0]['minutes'], $out['total_minutes']]);
    }

    public function test_another_accounts_entries_and_tasks_do_not_exist_for_the_agent(): void
    {
        $other = Account::factory()->create();
        $theirClient = Client::factory()->create(['account_id' => $other->id, 'name' => 'Obca Firma']);
        $theirProject = Project::create(['account_id' => $other->id, 'client_id' => $theirClient->id, 'name' => 'Their site']);
        $theirTask = Task::create(['project_id' => $theirProject->id, 'name' => 'Their task', 'source' => 'manual', 'list_name' => 'To-Do']);
        $theirs = TimeEntry::create(['account_id' => $other->id, 'project_id' => $theirProject->id, 'client_id' => $theirClient->id, 'task_id' => $theirTask->id, 'source' => 'manual', 'start_time' => '2026-10-06 08:00:00', 'end_time' => '2026-10-06 09:00:00', 'duration_minutes' => 60]);
        $mine = $this->entry($this->header, '2026-10-06 08:00:00', 60);

        [, $list] = $this->verb('crm:time-entries');
        [$exit, $edit] = $this->verb('crm:time-edit', ['entry' => (string) $theirs->id, '--minutes' => '5']);
        [$ok, $split] = $this->tool(TimeSplitTool::class, ['entry' => (string) $theirs->id, 'parts' => [['task' => (string) $this->form->id, 'minutes' => 5]]]);
        [, $move] = $this->verb('crm:time-edit', ['entry' => (string) $mine->id, '--task' => (string) $theirTask->id]);

        $this->assertSame([$mine->id], array_column($list['entries'], 'id'));
        $this->assertSame([1, 'not_found'], [$exit, $edit['error']]);
        $this->assertSame([false, 'not_found'], [$ok, $split['error']]);
        $this->assertSame('not_found', $move['error']);
        $this->assertSame([60, $theirTask->id], [$theirs->fresh()->duration_minutes, $theirs->fresh()->task_id]);
        $this->assertSame($this->header->id, $mine->fresh()->task_id);
    }

    public function test_an_entry_is_named_by_id_and_an_ambiguous_task_lists_the_candidates(): void
    {
        $entry = $this->entry($this->catchAll, '2026-10-06 08:00:00', 60);
        $this->task('Header fix');

        [, $byName] = $this->verb('crm:time-edit', ['entry' => 'Catch', '--minutes' => '5']);
        [$exit, $out] = $this->verb('crm:time-edit', ['entry' => (string) $entry->id, '--task' => 'Header']);

        $this->assertSame('not_found', $byName['error']);
        $this->assertStringContainsString('time-entries', $byName['message']);
        $this->assertSame([1, 'ambiguous_reference', 2], [$exit, $out['error'], count($out['candidates'])]);
        $this->assertSame($this->catchAll->id, $entry->fresh()->task_id);
    }

    public function test_card_text_is_untrusted_and_the_text_output_is_fenced(): void
    {
        $board = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Board', 'trello_board_id' => 'b1']);
        $card = Task::create(['project_id' => $board->id, 'name' => 'Card from the board', 'source' => 'trello', 'trello_card_id' => 'c1', 'list_name' => 'To-Do']);
        $onCard = $this->entry($card, '2026-10-06 08:00:00', 30);
        $manual = $this->entry($this->header, '2026-10-06 09:00:00', 30);

        [, $out] = $this->tool(TimeEntriesTool::class, []);
        $items = collect($out['entries'])->keyBy('id');

        $this->assertSame(['task', 'project', 'title', 'description'], $items[$onCard->id]['untrusted']);
        $this->assertSame(['title', 'description'], $items[$manual->id]['untrusted']);

        Artisan::call('crm:time-entries');
        $this->assertMatchesRegularExpression('/BEGIN EXTERNAL CONTENT [0-9a-f]+ .*\n.*Card from the board/s', Artisan::output());
    }

    public function test_minutes_keep_the_start_and_move_the_end(): void
    {
        $entry = $this->entry($this->header, '2026-10-06 08:00:00', 60);

        [$exit, $out] = $this->verb('crm:time-edit', ['entry' => (string) $entry->id, '--minutes' => '45']);

        $this->assertSame(0, $exit);
        $this->assertSame(['end', 'minutes'], $out['changed']);
        $entry->refresh();
        $this->assertSame(['2026-10-06 08:00:00', '2026-10-06 08:45:00', 45], [$entry->start_time->toDateTimeString(), $entry->end_time->toDateTimeString(), $entry->duration_minutes]);
    }

    public function test_start_and_end_are_warsaw_wall_clock_and_a_bare_time_stays_on_the_entrys_day(): void
    {
        $entry = $this->entry($this->header, '2026-10-05 08:00:00', 60);

        [, $out] = $this->tool(TimeEditTool::class, ['entry' => (string) $entry->id, 'start' => '2026-10-05 09:00', 'end' => '11:30']);

        $entry->refresh();
        $this->assertSame(['2026-10-05 07:00:00', '2026-10-05 09:30:00', 150], [$entry->start_time->toDateTimeString(), $entry->end_time->toDateTimeString(), $entry->duration_minutes]);
        $this->assertSame(['2026-10-05 09:00', '2026-10-05 11:30'], [$out['entry']['start'], $out['entry']['end']]);
    }

    public function test_minutes_with_an_end_move_the_start_and_impossible_times_refuse(): void
    {
        $entry = $this->entry($this->header, '2026-10-05 08:00:00', 60);
        $id = (string) $entry->id;

        $this->verb('crm:time-edit', ['entry' => $id, '--minutes' => '30', '--end' => '2026-10-05 12:00']);
        $this->assertSame(['2026-10-05 09:30:00', 30], [$entry->fresh()->start_time->toDateTimeString(), $entry->fresh()->duration_minutes]);

        foreach ([
            ['--minutes' => '30', '--start' => '09:00', '--end' => '10:00'],
            ['--start' => '2026-10-05 12:00', '--end' => '2026-10-05 11:00'],
            ['--end' => '2026-10-07 13:00'],
            ['--start' => '2026-03-29 02:30'],
            ['--start' => '2026-02-30 09:00'],
            ['--minutes' => '0'],
            ['--minutes' => 'lots'],
        ] as $options) {
            $this->assertSame('invalid_argument', $this->verb('crm:time-edit', ['entry' => $id, ...$options])[1]['error'], (string) json_encode($options));
        }
        $this->assertSame(30, $entry->fresh()->duration_minutes);
    }

    public function test_a_running_entry_refuses_time_but_takes_a_task_and_a_description(): void
    {
        $running = TimeEntry::startFor($this->catchAll);

        [, $time] = $this->verb('crm:time-edit', ['entry' => (string) $running->id, '--minutes' => '30']);
        [$exit, $out] = $this->verb('crm:time-edit', ['entry' => (string) $running->id, '--task' => (string) $this->header->id, '--desc' => 'Header work']);

        $this->assertSame('invalid_argument', $time['error']);
        $this->assertStringContainsString('stop it first', $time['message']);
        $this->assertSame([0, ['task', 'description']], [$exit, $out['changed']]);
        $running->refresh();
        $this->assertSame([$this->header->id, 'Header work', null], [$running->task_id, $running->description, $running->end_time]);
    }

    public function test_a_new_task_brings_its_project_and_client_like_time_log(): void
    {
        $florist = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Kwiaciarnia Fiolek']);
        $shop = Project::create(['account_id' => $this->account->id, 'client_id' => $florist->id, 'name' => 'Shop']);
        $order = Task::create(['project_id' => $shop->id, 'name' => 'Order page', 'source' => 'manual', 'list_name' => 'To-Do']);
        $entry = $this->entry($this->catchAll, '2026-10-06 08:00:00', 60);

        [, $out] = $this->verb('crm:time-edit', ['entry' => (string) $entry->id, '--task' => 'Order page', '--not-billable' => true]);

        $entry->refresh();
        $this->assertSame([$order->id, $shop->id, $florist->id, false], [$entry->task_id, $entry->project_id, $entry->client_id, (bool) $entry->billable]);
        $this->assertSame(['Kwiaciarnia Fiolek', 'Shop'], [$out['entry']['client'], $out['entry']['project']]);
    }

    public function test_the_catch_all_hours_split_onto_the_two_real_tasks(): void
    {
        $entry = $this->entry($this->catchAll, '2026-10-06 07:00:00', 272);

        [$exit, $out] = $this->verb('crm:time-split', ['entry' => (string) $entry->id, '--part' => [$this->header->id.':150', $this->form->id.':122:Form validation']]);

        $this->assertSame(0, $exit);
        $this->assertSame([0, 0], [$out['remainder_minutes'], $out['dropped_minutes']]);
        $this->assertSame($entry->id, $out['entries'][0]['id']);
        $rows = TimeEntry::query()->orderBy('start_time')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([$this->header->id, $this->form->id], $rows->pluck('task_id')->all());
        $this->assertSame([150, 122], $rows->pluck('duration_minutes')->all());
        $this->assertSame(['Header redesign', 'Form validation'], $rows->pluck('description')->all());
        $this->assertSame(['2026-10-06 07:00:00', '2026-10-06 09:30:00'], $rows->map(fn (TimeEntry $row): string => $row->start_time->toDateTimeString())->all());
        $this->assertSame('2026-10-06 11:32:00', $rows[1]->end_time->toDateTimeString());

        $audit = AgentAuditEvent::query()->where('target_type', 'time_entries')->orderBy('id')->get();
        $this->assertSame([['crm:time-split', 'updated', $entry->id], ['crm:time-split', 'created', $rows[1]->id]], $audit->map(fn (AgentAuditEvent $event): array => [$event->verb, $event->action, $event->target_id])->all());
        $this->assertSame($this->catchAll->id, $audit[0]->changes['before']['task_id']);
    }

    public function test_minutes_the_parts_leave_stay_on_the_original_after_them(): void
    {
        $entry = $this->entry($this->catchAll, '2026-10-06 07:00:00', 272);

        [, $out] = $this->tool(TimeSplitTool::class, ['entry' => (string) $entry->id, 'parts' => [['task' => 'Header', 'minutes' => 100], ['task' => (string) $this->form->id, 'minutes' => 100]]]);

        $this->assertSame([72, 0], [$out['remainder_minutes'], $out['dropped_minutes']]);
        $this->assertSame($entry->id, $out['entries'][2]['id']);
        $entry->refresh();
        $this->assertSame([$this->catchAll->id, 72, '2026-10-06 10:20:00', '2026-10-06 11:32:00'], [$entry->task_id, $entry->duration_minutes, $entry->start_time->toDateTimeString(), $entry->end_time->toDateTimeString()]);
        $this->assertSame(272, (int) TimeEntry::query()->sum('duration_minutes'));
    }

    public function test_the_rest_is_dropped_only_when_asked(): void
    {
        $entry = $this->entry($this->catchAll, '2026-10-06 07:00:00', 272);

        [, $out] = $this->verb('crm:time-split', ['entry' => (string) $entry->id, '--part' => [$this->header->id.':100'], '--drop-rest' => true]);

        $this->assertSame([0, 172], [$out['remainder_minutes'], $out['dropped_minutes']]);
        $this->assertSame(1, TimeEntry::query()->count());
        $entry->refresh();
        $this->assertSame([$this->header->id, 100, '2026-10-06 08:40:00'], [$entry->task_id, $entry->duration_minutes, $entry->end_time->toDateTimeString()]);
    }

    public function test_a_split_that_does_not_fit_refuses_and_writes_nothing(): void
    {
        $entry = $this->entry($this->catchAll, '2026-10-06 07:00:00', 60);
        $running = TimeEntry::startFor($this->catchAll);
        $id = (string) $entry->id;

        $this->assertSame(60, $this->verb('crm:time-split', ['entry' => $id, '--part' => [$this->header->id.':40', $this->form->id.':30']])[1]['entry_minutes']);
        $this->assertSame('invalid_argument', $this->verb('crm:time-split', ['entry' => $id, '--part' => [$this->header->id.':0']])[1]['error']);
        $this->assertSame('invalid_argument', $this->verb('crm:time-split', ['entry' => $id, '--part' => ['nominutes']])[1]['error']);
        $this->assertSame('invalid_argument', $this->verb('crm:time-split', ['entry' => $id])[1]['error']);
        $this->assertStringContainsString('stop it first', $this->verb('crm:time-split', ['entry' => (string) $running->id, '--part' => [$this->header->id.':1']])[1]['message']);

        $this->assertSame(2, TimeEntry::query()->count());
        $this->assertSame([$this->catchAll->id, 60], [$entry->fresh()->task_id, $entry->fresh()->duration_minutes]);
    }

    public function test_a_split_of_a_timer_rounded_up_never_runs_past_its_real_end(): void
    {
        $entry = TimeEntry::create(['account_id' => $this->account->id, 'project_id' => $this->site->id, 'client_id' => $this->client->id, 'task_id' => $this->catchAll->id, 'source' => 'manual', 'start_time' => '2026-10-06 07:00:00', 'end_time' => '2026-10-06 07:01:30', 'duration_minutes' => 2]);

        $this->verb('crm:time-split', ['entry' => (string) $entry->id, '--part' => [$this->header->id.':1', $this->form->id.':1']]);

        $last = TimeEntry::query()->orderByDesc('start_time')->first();
        $this->assertSame(['2026-10-06 07:01:00', '2026-10-06 07:01:30', 1], [$last->start_time->toDateTimeString(), $last->end_time->toDateTimeString(), $last->duration_minutes]);
    }

    public function test_a_closed_month_refuses_edits_inside_it_and_moves_across_its_edge(): void
    {
        ClientMonthClosure::create(['client_id' => $this->client->id, 'period' => '2026-09', 'closed_at' => now(), 'snapshot' => ['closing' => 0.0]]);
        $august = $this->entry($this->header, '2026-08-20 08:00:00', 60);
        $september = $this->entry($this->header, '2026-09-30 21:30:00', 60);
        $october = $this->entry($this->header, '2026-10-01 08:00:00', 60);

        [, $list] = $this->tool(TimeEntriesTool::class, []);
        $items = collect($list['entries'])->keyBy('id');
        $this->assertSame([false, false, true], [$items[$august->id]['editable'], $items[$september->id]['editable'], $items[$october->id]['editable']]);
        $this->assertStringContainsString('2026-09 is closed', (string) $items[$september->id]['locked']);

        [$exit, $inside] = $this->verb('crm:time-edit', ['entry' => (string) $august->id, '--minutes' => '30']);
        [, $into] = $this->verb('crm:time-edit', ['entry' => (string) $october->id, '--start' => '2026-09-30 22:00', '--end' => '2026-09-30 23:00']);
        [, $split] = $this->tool(TimeSplitTool::class, ['entry' => (string) $september->id, 'parts' => [['task' => (string) $this->form->id, 'minutes' => 30]]]);

        $this->assertSame([1, 'period_settled', 'closed', '2026-09'], [$exit, $inside['error'], $inside['reason'], $inside['month']]);
        $this->assertSame(['period_settled', '2026-09-30'], [$into['error'], $into['day']]);
        $this->assertSame('period_settled', $split['error']);
        $this->assertSame(3, TimeEntry::query()->count());
        $this->assertSame('2026-10-01 08:00:00', $october->fresh()->start_time->toDateTimeString());
        $this->assertSame('2026-09-30 23:30', $items[$september->id]['start']);
    }

    public function test_a_closed_month_takes_a_new_title_and_description_but_not_billable(): void
    {
        ClientMonthClosure::create(['client_id' => $this->client->id, 'period' => '2026-09', 'closed_at' => now(), 'snapshot' => ['closing' => 0.0]]);
        $september = $this->entry($this->header, '2026-09-15 08:00:00', 60);

        [$exit, $text] = $this->verb('crm:time-edit', ['entry' => (string) $september->id, '--desc' => 'Header copy', '--title' => 'Header']);
        [$ok, $billable] = $this->tool(TimeEditTool::class, ['entry' => (string) $september->id, 'billable' => false]);

        $this->assertSame([0, ['description', 'title']], [$exit, $text['changed']]);
        $this->assertSame([false, 'period_settled'], [$ok, $billable['error']]);
        $this->assertSame(['Header copy', 'Header', true, 60], [$september->fresh()->description, $september->fresh()->title, $september->fresh()->billable, $september->fresh()->duration_minutes]);
    }

    public function test_time_log_refuses_time_ending_in_a_closed_month_on_the_verb_and_the_tool(): void
    {
        ClientMonthClosure::create(['client_id' => $this->client->id, 'period' => '2026-09', 'closed_at' => now(), 'snapshot' => ['closing' => 0.0]]);

        $exit = Artisan::call('time:log', ['minutes' => 60, '--task' => (string) $this->header->id, '--end' => '2026-09-30 15:00']);
        $verb = Artisan::output();
        [$ok, $tool] = $this->tool(LogTimeTool::class, ['minutes' => 60, 'task' => (string) $this->header->id, 'end' => '2026-09-30 15:00']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('2026-09 is closed', $verb);
        $this->assertSame([false, 'period_settled', 'closed', '2026-09', '2026-09-30'], [$ok, $tool['error'], $tool['reason'], $tool['month'], $tool['day']]);
        $this->assertSame(0, TimeEntry::query()->count());
        $this->assertSame(0, Artisan::call('time:log', ['minutes' => 60, '--task' => (string) $this->header->id, '--end' => '2026-10-01 15:00']));
    }

    public function test_time_cannot_move_out_of_a_closed_month_or_onto_a_client_whose_month_is_closed(): void
    {
        $florist = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Kwiaciarnia Fiolek']);
        $shop = Project::create(['account_id' => $this->account->id, 'client_id' => $florist->id, 'name' => 'Shop']);
        $order = Task::create(['project_id' => $shop->id, 'name' => 'Order page', 'source' => 'manual', 'list_name' => 'To-Do']);
        ClientMonthClosure::create(['client_id' => $florist->id, 'period' => '2026-09', 'closed_at' => now(), 'snapshot' => ['closing' => 0.0]]);
        $september = $this->entry($this->header, '2026-09-15 08:00:00', 60);

        [, $move] = $this->verb('crm:time-edit', ['entry' => (string) $september->id, '--task' => (string) $order->id]);
        [, $part] = $this->verb('crm:time-split', ['entry' => (string) $september->id, '--part' => [$this->form->id.':30', $order->id.':30']]);

        $this->assertSame(['period_settled', $florist->id], [$move['error'], $move['client_id']]);
        $this->assertSame(['period_settled', $florist->id], [$part['error'], $part['client_id']]);
        $this->assertSame(1, TimeEntry::query()->count());
        $this->assertSame($this->header->id, $september->fresh()->task_id);

        ClientMonthClosure::create(['client_id' => $this->client->id, 'period' => '2026-09', 'closed_at' => now(), 'snapshot' => ['closing' => 0.0]]);
        [, $out] = $this->verb('crm:time-edit', ['entry' => (string) $september->id, '--start' => '2026-10-02 09:00', '--end' => '2026-10-02 10:00']);
        $this->assertSame('period_settled', $out['error']);
    }

    public function test_a_finalized_or_sent_report_settles_the_days_up_to_its_end_and_a_draft_does_not(): void
    {
        $inside = $this->entry($this->header, '2026-10-05 08:00:00', 60);
        $report = ClientReport::create([
            'account_id' => $this->account->id, 'client_id' => $this->client->id, 'period_type' => ClientReport::PERIOD_MONTH,
            'status' => ClientReport::STATUS_DRAFT, 'period_start' => '2026-10-01', 'period_end' => '2026-10-05',
            'contracted_hours' => 10, 'opening_balance_hours' => 0, 'actual_hours' => 1, 'composer_key' => 'manual',
        ]);

        $this->assertSame(0, $this->verb('crm:time-edit', ['entry' => (string) $inside->id, '--minutes' => '50'])[0]);

        $report->update(['status' => ClientReport::STATUS_FINALIZED]);
        [, $out] = $this->tool(TimeEditTool::class, ['entry' => (string) $inside->id, 'minutes' => 40]);
        [, $after] = $this->verb('crm:time-edit', ['entry' => (string) $inside->id, '--start' => '2026-10-06 09:00', '--end' => '2026-10-06 10:00']);

        $this->assertSame(['period_settled', 'reported', '2026-10'], [$out['error'], $out['reason'], $out['month']]);
        $this->assertSame('period_settled', $after['error']);
        $this->assertSame(50, $inside->fresh()->duration_minutes);
    }

    public function test_an_entry_whose_minutes_disagree_with_its_span_is_not_split(): void
    {
        $entry = $this->entry($this->catchAll, '2026-10-06 10:00:00', 60);
        $entry->update(['duration_minutes' => 120]);

        [, $out] = $this->verb('crm:time-split', ['entry' => (string) $entry->id, '--part' => [$this->header->id.':90']]);

        $this->assertSame(['invalid_argument', 120, 60], [$out['error'], $out['entry_minutes'], $out['span_minutes']]);
        $this->assertSame(1, TimeEntry::query()->count());
        $this->assertSame($this->catchAll->id, $entry->fresh()->task_id);
    }

    public function test_an_mcp_entry_that_is_not_an_id_touches_nothing(): void
    {
        $entry = $this->entry($this->header, '2026-10-06 08:00:00', 60);

        CrmServer::tool(TimeEditTool::class, ['entry' => true, 'minutes' => 7])->assertHasErrors();
        CrmServer::tool(TimeSplitTool::class, ['entry' => true, 'parts' => [['task' => (string) $this->form->id, 'minutes' => 10]]])->assertHasErrors();
        [$ok] = $this->tool(TimeEditTool::class, ['entry' => $entry->id, 'minutes' => 30]);

        $this->assertTrue($ok);
        $this->assertSame(1, TimeEntry::query()->count());
        $this->assertSame(30, $entry->fresh()->duration_minutes);
    }

    public function test_descriptions_pass_the_humanizer_gate(): void
    {
        $entry = $this->entry($this->header, '2026-10-06 08:00:00', 60);

        $this->tool(TimeEditTool::class, ['entry' => (string) $entry->id, 'description' => "Hero copy \u{2014} \u{201C}final\u{201D}"]);
        $this->verb('crm:time-split', ['entry' => (string) $entry->id, '--part' => [$this->form->id.":30:Form \u{2013} checks"]]);

        foreach (TimeEntry::query()->pluck('description') as $description) {
            $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}\x{201C}\x{201D}]/u', (string) $description);
        }
    }

    public function test_an_edit_writes_one_audit_entry_with_what_it_replaced(): void
    {
        $entry = $this->entry($this->header, '2026-10-06 08:00:00', 60);

        $this->verb('crm:time-edit', ['entry' => (string) $entry->id, '--minutes' => '90', '--title' => 'Hero']);

        $audit = AgentAuditEvent::query()->where('target_type', 'time_entries')->sole();
        $this->assertSame(['crm:time-edit', 'updated', $entry->id], [$audit->verb, $audit->action, $audit->target_id]);
        $this->assertSame(60, (int) $audit->changes['before']['duration_minutes']);
        $this->assertArrayHasKey('title', $audit->changes['before']);
    }

    private function task(string $name): Task
    {
        return Task::create(['project_id' => $this->site->id, 'name' => $name, 'source' => 'manual', 'list_name' => 'To-Do']);
    }

    private function entry(Task $task, string $startUtc, int $minutes): TimeEntry
    {
        $task->loadMissing('project');
        $start = Carbon::parse($startUtc, 'UTC');

        return TimeEntry::create([
            'account_id' => $task->project->account_id,
            'project_id' => $task->project_id,
            'client_id' => $task->project->client_id,
            'task_id' => $task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'description' => $task->name,
            'start_time' => $start,
            'end_time' => $start->copy()->addMinutes($minutes),
            'duration_minutes' => $minutes,
            'billable' => true,
        ]);
    }
}
