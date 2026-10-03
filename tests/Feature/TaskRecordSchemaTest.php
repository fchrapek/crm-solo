<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskBrief;
use App\Models\TaskCardDetails;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Agent\TaskRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The crm.task/1 contract: the same keys, with the same types, for every kind
 * of task. An agent parsing one record can parse them all.
 */
final class TaskRecordSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const array TOP_LEVEL = [
        'schema', 'id', 'name', 'state', 'finished_at', 'card_lane', 'due', 'is_overdue',
        'client', 'project', 'source', 'description', 'checklists', 'comments', 'attachments',
        'links', 'brief', 'readiness', 'time', 'untrusted',
    ];

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $account = Account::factory()->create();
        $client = Client::factory()->create(['account_id' => $account->id, 'name' => 'Schema Co']);
        $this->project = Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => 'Site']);
        Repository::create(['project_id' => $this->project->id, 'name' => 'site', 'local_path' => '/tmp/site', 'provider' => 'local']);
    }

    public function test_a_manual_task_has_every_key_with_empty_values_as_null_or_empty_lists(): void
    {
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Manual', 'source' => 'manual', 'list_name' => 'To-Do']);

        $record = app(TaskRecord::class)->build($task);

        $this->assertShape($record);
        $this->assertSame('crm.task/1', $record['schema']);
        $this->assertSame('open', $record['state']);
        $this->assertSame('manual', $record['source']['type']);
        $this->assertNull($record['card_lane']);
        $this->assertNull($record['description']['markdown']);
        $this->assertTrue($record['description']['is_empty']);
        $this->assertSame(['attachments'], $record['untrusted']);
        $this->assertSame('/tmp/site', $record['project']['repository_path']);
        $this->assertSame(['description', 'target', 'done_condition'], $record['readiness']['missing']);
    }

    public function test_a_trello_task_with_nothing_fetched_has_the_same_keys(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Card',
            'source' => 'trello',
            'trello_card_id' => 'card1',
            'trello_url' => 'https://trello.com/c/abc',
            'list_name' => 'Doing',
            'description' => "Zmień stopkę na stronie [https://example.com](https://example.com \"\")\r\n",
            'trello_activity_at' => now(),
        ]);

        $record = app(TaskRecord::class)->build($task);

        $this->assertShape($record);
        $this->assertSame('trello', $record['source']['type']);
        $this->assertSame('Doing', $record['card_lane']);
        $this->assertSame('https://trello.com/c/abc', $record['source']['card_url']);
        $this->assertSame('Zmień stopkę na stronie https://example.com', $record['description']['markdown']);
        $this->assertSame([['url' => 'https://example.com', 'text' => null, 'from' => 'description']], $record['links']);
        $this->assertContains('description', $record['untrusted']);
        $this->assertContains('source.card_url', $record['untrusted']);
        $this->assertContains('project.name', $record['untrusted']);
        $this->assertContains('name', $record['untrusted']);
    }

    public function test_a_trello_task_with_everything_fetched_keeps_the_same_keys(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id, 'name' => 'Card', 'source' => 'trello', 'trello_card_id' => 'card2',
            'trello_url' => 'https://trello.com/c/def', 'list_name' => 'To-Do', 'due_date' => '2026-10-05 12:00:00',
            'description' => 'Podmień zdjęcie na stronie głównej, plik w załączniku.',
        ]);
        TaskAttachment::create([
            'task_id' => $task->id, 'file_path' => "task-attachments/{$task->id}/x.png", 'original_name' => 'hero.png',
            'mime' => 'image/png', 'size' => 120, 'trello_attachment_id' => 'att1',
        ]);
        TaskCardDetails::create([
            'task_id' => $task->id,
            'checklists' => [['name' => 'QA', 'items' => [['name' => 'Sprawdź na telefonie', 'done' => false]]]],
            'comments' => [['author' => 'Anna K', 'at' => '2026-10-01T08:00:00+00:00', 'text' => 'Zdjęcie w załączniku']],
            'card_attachments' => [
                ['trello_id' => 'att1', 'name' => 'hero.png', 'mime' => 'image/png', 'size' => 120, 'url' => 'https://trello.com/1/cards/card2/attachments/att1/download/hero.png', 'status' => 'saved', 'reason' => null, 'attachment_id' => 1],
                ['trello_id' => 'att2', 'name' => 'setup.exe', 'mime' => null, 'size' => 10, 'url' => 'https://trello.com/1/cards/card2/attachments/att2/download/setup.exe', 'status' => 'refused', 'reason' => 'This file type is not allowed.', 'attachment_id' => null],
                ['trello_id' => 'att3', 'name' => 'Makieta', 'mime' => null, 'size' => null, 'url' => 'https://www.figma.com/file/x', 'status' => 'link', 'reason' => null, 'attachment_id' => null],
            ],
            'card_activity_at' => now(),
            'fetched_at' => now(),
        ]);

        $owner = User::factory()->create(['account_id' => $this->project->account_id]);
        TaskBrief::create([
            'task_id' => $task->id, 'location' => 'Strona główna, sekcja hero', 'done_when' => 'Nowe zdjęcie widoczne',
            'drafted_by_user_id' => $owner->id, 'drafted_via' => 'mcp', 'drafted_at' => now(),
            'confirmations' => [
                'where' => ['at' => now()->toIso8601String(), 'user_id' => $owner->id],
                'done_when' => ['at' => now()->toIso8601String(), 'user_id' => $owner->id],
            ],
        ]);

        $record = app(TaskRecord::class)->build($task);

        $this->assertShape($record);
        $this->assertSame('Strona główna, sekcja hero', $record['brief']['where']);
        $this->assertIsString($record['brief']['confirmed_at']);
        $this->assertCount(1, $record['checklists']);
        $this->assertCount(1, $record['comments']);
        $this->assertSame(['saved', 'refused'], array_column($record['attachments'], 'status'));
        $this->assertSame(['trello', 'trello'], array_column($record['attachments'], 'from'));
        $this->assertSame('card_attachment', $record['links'][0]['from']);
        $this->assertSame('2026-10-05', $record['due']);
        $this->assertTrue($record['readiness']['ready']);
        $this->assertContains('comments', $record['untrusted']);
        $this->assertContains('attachments', $record['untrusted']);
    }

    public function test_a_finished_task_reports_its_finish(): void
    {
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Finished', 'source' => 'manual', 'finished_at' => now(), 'is_completed' => true, 'list_name' => 'Done']);
        TimeEntry::create([
            'account_id' => $this->project->account_id, 'project_id' => $this->project->id, 'task_id' => $task->id,
            'source' => 'manual', 'start_time' => now()->subHour(), 'end_time' => now(), 'duration_minutes' => 60,
        ]);

        $record = app(TaskRecord::class)->build($task);

        $this->assertShape($record);
        $this->assertSame('finished', $record['state']);
        $this->assertIsString($record['finished_at']);
        $this->assertSame(60, $record['time']['minutes']);
    }

    public function test_an_archived_task_reads_as_archived(): void
    {
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Old', 'source' => 'manual', 'archived_at' => now()]);
        TimeEntry::create([
            'account_id' => $this->project->account_id, 'project_id' => $this->project->id, 'task_id' => $task->id,
            'source' => 'manual', 'start_time' => now()->subMinutes(5),
        ]);

        $record = app(TaskRecord::class)->build($task);

        $this->assertShape($record);
        $this->assertSame('archived', $record['state']);
        $this->assertIsInt($record['time']['running_timer']['entry_id']);
    }

    public function test_only_an_open_task_reports_overdue(): void
    {
        $past = now()->subDays(10)->toDateString();
        $open = Task::create(['project_id' => $this->project->id, 'name' => 'Open', 'source' => 'manual', 'due_date' => $past, 'list_name' => 'To-Do']);
        $archived = Task::create(['project_id' => $this->project->id, 'name' => 'Archived', 'source' => 'manual', 'due_date' => $past, 'archived_at' => now()]);
        $onDone = Task::create(['project_id' => $this->project->id, 'name' => 'On Done', 'source' => 'manual', 'due_date' => $past, 'list_name' => 'Done', 'is_completed' => false]);

        $records = collect([$open, $archived, $onDone])->map(fn (Task $task) => app(TaskRecord::class)->build($task));

        $this->assertSame(['open', 'archived', 'completed'], $records->pluck('state')->all());
        $this->assertSame([true, false, false], $records->pluck('is_overdue')->all());
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function assertShape(array $record): void
    {
        $this->assertSame(self::TOP_LEVEL, array_keys($record));
        $this->assertSame('crm.task/1', $record['schema']);
        $this->assertIsInt($record['id']);
        $this->assertIsString($record['name']);
        $this->assertContains($record['state'], ['open', 'finished', 'completed', 'archived']);
        $this->assertNullOr('string', $record['finished_at']);
        $this->assertNullOr('string', $record['card_lane']);
        $this->assertNullOr('string', $record['due']);
        $this->assertIsBool($record['is_overdue']);

        $this->assertNullOrKeys(['id', 'name'], $record['client']);
        $this->assertNullOrKeys(['id', 'name', 'repository_path'], $record['project']);

        $this->assertSame(['type', 'card_url', 'list', 'last_activity_at', 'details_fetched_at', 'fetch_error'], array_keys($record['source']));
        $this->assertContains($record['source']['type'], ['trello', 'manual']);
        foreach (['card_url', 'list', 'last_activity_at', 'details_fetched_at', 'fetch_error'] as $key) {
            $this->assertNullOr('string', $record['source'][$key]);
        }

        $this->assertSame(['markdown', 'is_empty'], array_keys($record['description']));
        $this->assertNullOr('string', $record['description']['markdown']);
        $this->assertIsBool($record['description']['is_empty']);

        $this->assertListOf(['name', 'items'], $record['checklists']);
        foreach ($record['checklists'] as $checklist) {
            $this->assertListOf(['name', 'done'], $checklist['items']);
        }
        $this->assertListOf(['author', 'at', 'text'], $record['comments']);
        $this->assertListOf(['id', 'name', 'mime', 'size', 'path', 'url', 'from', 'status', 'reason'], $record['attachments']);
        foreach ($record['attachments'] as $attachment) {
            $this->assertContains($attachment['from'], ['upload', 'trello']);
            $this->assertContains($attachment['status'], ['saved', 'refused', 'failed']);
        }
        $this->assertListOf(['url', 'text', 'from'], $record['links']);

        $this->assertSame(
            ['where', 'done_when', 'constraints', 'notes', 'drafted_by', 'drafted_at', 'confirmed_at', 'confirmed_by', 'unconfirmed'],
            array_keys($record['brief']),
        );
        foreach (['where', 'done_when', 'constraints', 'notes', 'drafted_at', 'confirmed_at'] as $key) {
            $this->assertNullOr('string', $record['brief'][$key]);
        }
        $this->assertNullOrKeys(['id', 'name', 'via'], $record['brief']['drafted_by']);
        $this->assertNullOrKeys(['id', 'name'], $record['brief']['confirmed_by']);
        $this->assertIsList($record['brief']['unconfirmed']);

        $this->assertSame(['ready', 'missing'], array_keys($record['readiness']));
        $this->assertIsBool($record['readiness']['ready']);
        $this->assertIsList($record['readiness']['missing']);

        $this->assertSame(['minutes', 'running_timer'], array_keys($record['time']));
        $this->assertIsInt($record['time']['minutes']);
        $this->assertNullOrKeys(['entry_id', 'started_at'], $record['time']['running_timer']);

        $this->assertIsList($record['untrusted']);
    }

    private function assertNullOr(string $type, mixed $value): void
    {
        $this->assertTrue($value === null || get_debug_type($value) === $type, "Expected null or {$type}, got ".get_debug_type($value));
    }

    /**
     * @param  list<string>  $keys
     */
    private function assertNullOrKeys(array $keys, mixed $value): void
    {
        if ($value !== null) {
            $this->assertIsArray($value);
            $this->assertSame($keys, array_keys($value));
        }
    }

    /**
     * @param  list<string>  $keys
     */
    private function assertListOf(array $keys, mixed $value): void
    {
        $this->assertIsList($value);
        foreach ($value as $item) {
            $this->assertSame($keys, array_keys($item));
        }
    }
}
