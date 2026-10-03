<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskBrief;
use App\Models\TaskCardDetails;
use App\Services\Agent\ClientBrief;
use App\Services\Agent\TodayDigest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Task lists tell an agent which tasks it can start on: the card link,
 * whether there is a description, and the readiness hint, all from data
 * already loaded, never from Trello and never one query per task.
 */
final class TaskListFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $account = Account::factory()->create();
        $this->client = Client::factory()->create(['account_id' => $account->id]);
        $this->project = Project::create(['account_id' => $account->id, 'client_id' => $this->client->id, 'name' => 'Board', 'trello_board_id' => 'b1']);
    }

    public function test_brief_lists_card_url_description_and_readiness(): void
    {
        $ready = $this->task('Ready', 'Zmień numer telefonu w stopce na nowy numer biura.', 'https://trello.com/c/r');
        TaskBrief::create(['task_id' => $ready->id, 'location' => 'footer.php', 'done_when' => 'Numer widoczny']);
        $this->task('Empty', null, 'https://trello.com/c/e');

        $tasks = collect(app(ClientBrief::class)->for($this->client)['open_tasks'])->keyBy('name');

        $this->assertSame(['card_url' => 'https://trello.com/c/r', 'has_description' => true, 'ready' => true], array_intersect_key($tasks['Ready'], array_flip(['card_url', 'has_description', 'ready'])));
        $this->assertFalse($tasks['Empty']['has_description']);
        $this->assertFalse($tasks['Empty']['ready']);
    }

    public function test_list_items_say_which_of_their_keys_hold_card_text(): void
    {
        $card = $this->task('Card', null, 'https://trello.com/c/k');
        $card->update(['priority' => 'high']);
        $manual = Task::create(['project_id' => $this->project->id, 'name' => 'Mine', 'source' => 'manual', 'priority' => 'high']);
        $lead = Lead::create(['account_id' => $this->client->account_id, 'pipeline' => Lead::pipelines()[0], 'name' => 'Form lead', 'source' => Lead::sources()[0]]);
        config(['leadgen.tiers.gold_min' => 0]);

        $brief = collect(app(ClientBrief::class)->for($this->client)['open_tasks'])->keyBy('id');
        $today = app(TodayDigest::class)->build($this->client->account_id);

        $this->assertSame(['name', 'project', 'list', 'card_lane', 'card_url'], $brief[$card->id]['untrusted']);
        $this->assertSame([], $brief[$manual->id]['untrusted']);
        $this->assertSame(['name', 'project', 'card_lane', 'card_url'], $today['attention_tasks']->firstWhere('id', $card->id)['untrusted']);
        $this->assertSame(['name'], $today['hot_leads']->firstWhere('id', $lead->id)['untrusted']);
    }

    public function test_today_lists_the_same_fields(): void
    {
        $task = $this->task('Urgent', 'Zmień numer telefonu w stopce na nowy numer biura.', 'https://trello.com/c/u');
        $task->update(['priority' => 'high']);

        $listed = app(TodayDigest::class)->build($this->client->account_id)['attention_tasks']->firstWhere('id', $task->id);

        $this->assertSame('https://trello.com/c/u', $listed['card_url']);
        $this->assertTrue($listed['has_description']);
        $this->assertFalse($listed['ready']);
    }

    public function test_brief_costs_the_same_queries_for_one_task_or_many(): void
    {
        $this->detailedTask('First');
        $one = $this->countQueries(fn () => app(ClientBrief::class)->for($this->client));

        foreach (range(2, 8) as $i) {
            $this->detailedTask("Task {$i}");
        }
        $many = $this->countQueries(fn () => app(ClientBrief::class)->for($this->client));

        $this->assertSame($one, $many);
        Http::assertNothingSent();
        $this->assertTrue(collect(app(ClientBrief::class)->for($this->client)['open_tasks'])->every(fn (array $t): bool => $t['ready']));
        $this->assertNoCardJsonLoaded(fn () => app(ClientBrief::class)->for($this->client));
    }

    public function test_today_costs_the_same_queries_for_one_task_or_many(): void
    {
        $this->detailedTask('First')->update(['priority' => 'high']);
        $one = $this->countQueries(fn () => app(TodayDigest::class)->build($this->client->account_id));

        foreach (range(2, 8) as $i) {
            $this->detailedTask("Task {$i}")->update(['priority' => 'high']);
        }
        $many = $this->countQueries(fn () => app(TodayDigest::class)->build($this->client->account_id));

        $this->assertSame($one, $many);
        Http::assertNothingSent();
        $this->assertTrue(app(TodayDigest::class)->build($this->client->account_id)['attention_tasks']->every(fn (array $t): bool => $t['ready']));
        $this->assertNoCardJsonLoaded(fn () => app(TodayDigest::class)->build($this->client->account_id));
    }

    private function task(string $name, ?string $description, string $url): Task
    {
        // A counter, not a random number: card ids are unique per project.
        static $card = 0;

        return Task::create([
            'project_id' => $this->project->id, 'name' => $name, 'source' => 'trello', 'trello_card_id' => 'c'.(++$card),
            'trello_url' => $url, 'list_name' => 'To-Do', 'description' => $description,
        ]);
    }

    /**
     * Ready only through the card: its target is a link attachment and its
     * done condition a checklist, while the brief exists but says neither, so
     * readiness has to read both relations for every task.
     */
    private function detailedTask(string $name): Task
    {
        $task = $this->task($name, 'Opis zadania bez linku, ale z wystarczajaco dluga trescia.', 'https://trello.com/c/x');
        TaskBrief::create(['task_id' => $task->id, 'notes' => 'Tylko notatka']);
        TaskCardDetails::create([
            'task_id' => $task->id,
            'checklists' => [['name' => 'QA', 'items' => [['name' => 'a', 'done' => false]]]],
            'comments' => [['author' => 'A', 'at' => null, 'text' => str_repeat('long comment history ', 50)]],
            'card_attachments' => [['trello_id' => 'x', 'name' => 'Makieta', 'mime' => null, 'size' => null, 'url' => 'https://www.figma.com/file/x', 'status' => 'link', 'reason' => null, 'attachment_id' => null]],
            'fetched_at' => now(),
        ]);

        return $task;
    }

    private function assertNoCardJsonLoaded(callable $run): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $sql = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q): bool => str_contains($q, 'task_card_details'));
        DB::disableQueryLog();

        $this->assertNotEmpty($sql);
        foreach ($sql as $query) {
            $this->assertStringNotContainsString('comments', $query);
            $this->assertStringNotContainsString('*', $query);
        }
    }

    private function countQueries(callable $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
