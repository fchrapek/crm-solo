<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Integration;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskCardDetails;
use App\Services\Agent\TaskReader;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * How card fetches fail, wait and stop: failures back off, a gone or archived
 * card is left alone, a failed file is retried after its own backoff, the
 * whole fetch fits its lock lease, older details never overwrite newer ones,
 * the quota is counted at commit, and files the card dropped are removed.
 */
final class TaskCardFetchPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const string CARD = 'abc123';

    private Task $task;

    /** @var array<string, mixed> */
    private array $card;

    private int $cardStatus = 200;

    /** @var array<string, Closure(): mixed> */
    private array $downloads = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-10-01 12:00:00');

        $account = Account::factory()->create();
        $client = Client::factory()->create(['account_id' => $account->id]);
        $project = Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => 'Board', 'trello_board_id' => 'b1']);
        Integration::create(['account_id' => $account->id, 'provider' => 'trello', 'is_enabled' => true, 'api_key' => 't', 'settings' => ['trello_api_key' => 'k']]);
        $this->task = Task::create(['project_id' => $project->id, 'name' => 'Card', 'source' => 'trello', 'trello_card_id' => self::CARD, 'list_name' => 'Doing']);
        $this->card = ['id' => self::CARD, 'dateLastActivity' => '2026-10-01T10:00:00.000Z', 'checklists' => [], 'actions' => [], 'attachments' => []];

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            foreach ($this->downloads as $suffix => $respond) {
                if (str_ends_with($path, $suffix)) {
                    return $respond();
                }
            }

            return $path === '/1/cards/'.self::CARD
                ? Http::response($this->cardStatus === 200 ? $this->card : ['message' => 'no'], $this->cardStatus)
                : Http::response('unexpected', 418);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_failing_card_backs_off_and_doubles_its_wait(): void
    {
        $this->cardStatus = 500;

        $this->assertSame('Trello answered HTTP 500.', $this->read()['source']['fetch_error']);
        $this->assertSame('Trello answered HTTP 500.', $this->read()['source']['fetch_error']);
        $this->assertCardRequests(1);

        Carbon::setTestNow(now()->addMinutes(6));
        $this->read();
        $this->assertCardRequests(2);

        Carbon::setTestNow(now()->addMinutes(6));
        $this->read();
        $this->assertCardRequests(2);

        $this->cardStatus = 200;
        Carbon::setTestNow(now()->addMinutes(5));
        $this->assertNull($this->read()['source']['fetch_error']);
        $this->assertCardRequests(3);
        $this->assertSame(0, TaskCardDetails::sole()->fetch_failures);
    }

    public function test_refresh_overrides_the_backoff(): void
    {
        $this->cardStatus = 500;
        $this->read();

        $this->read(refresh: true);

        $this->assertCardRequests(2);
    }

    public function test_a_card_gone_from_trello_is_not_fetched_again(): void
    {
        $this->cardStatus = 404;

        $this->assertSame('The card is gone from Trello; details are no longer fetched.', $this->read()['source']['fetch_error']);
        Carbon::setTestNow(now()->addDays(3));
        $this->task->update(['trello_activity_at' => now()]);
        $this->read();
        $this->assertCardRequests(1);

        $this->cardStatus = 200;
        $this->assertNull($this->read(refresh: true)['source']['fetch_error']);
        $this->assertCardRequests(2);
        $this->assertNull(TaskCardDetails::sole()->card_gone_at);
    }

    public function test_a_card_the_sync_archived_is_not_fetched(): void
    {
        $this->task->update(['archived_at' => now()]);

        $this->read();
        Http::assertNothingSent();

        $this->read(refresh: true);
        $this->assertCardRequests(1);
    }

    public function test_a_failed_file_is_retried_after_its_backoff_and_reported_meanwhile(): void
    {
        $this->cardFile('att1', 'shot.png');
        $this->downloads['/attachments/att1/download/shot.png'] = fn () => Http::response('busy', 503);

        $first = $this->read();
        $this->assertSame('failed', $first['attachments'][0]['status']);
        $this->assertSame('Trello answered HTTP 503.', $first['attachments'][0]['reason']);
        $this->assertStringContainsString('could not be downloaded yet', (string) $first['source']['fetch_error']);

        $this->read();
        $this->assertCardRequests(1);

        $this->downloads['/attachments/att1/download/shot.png'] = fn () => Http::response($this->png());
        Carbon::setTestNow(now()->addMinutes(6));
        $retried = $this->read();

        $this->assertCardRequests(2);
        $this->assertSame('saved', $retried['attachments'][0]['status']);
        $this->assertNull($retried['source']['fetch_error']);
    }

    public function test_downloads_stop_before_the_lock_lease_runs_out(): void
    {
        foreach (['a1', 'a2', 'a3'] as $id) {
            $this->cardFile($id, "{$id}.png");
            $this->downloads["/attachments/{$id}/download/{$id}.png"] = function () {
                Carbon::setTestNow(now()->addSeconds(140));

                return Http::response($this->png());
            };
        }

        $record = $this->read();

        $this->assertSame(2, Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/download/'))->count());
        $this->assertSame(['saved', 'saved', 'failed'], array_column($record['attachments'], 'status'));
        $this->assertStringContainsString('ran out of time', (string) $record['attachments'][2]['reason']);
        $this->assertNotNull(TaskCardDetails::sole()->fetched_at);
    }

    public function test_older_card_details_never_overwrite_newer_ones(): void
    {
        TaskCardDetails::create([
            'task_id' => $this->task->id,
            'checklists' => [['name' => 'Newer', 'items' => []]],
            'card_activity_at' => '2026-10-01 11:00:00',
            'fetched_at' => now(),
        ]);

        $record = $this->read(refresh: true);

        $this->assertSame('Newer', $record['checklists'][0]['name']);
    }

    public function test_the_quota_is_counted_again_when_a_file_is_stored(): void
    {
        config(['services.trello.attachments_task_max_kb' => 1]);
        $this->cardFile('att1', 'shot.png');
        $this->card['attachments'][] = ['id' => 'other', 'name' => 'Link', 'url' => 'https://example.com/x', 'isUpload' => false];
        $this->downloads['/attachments/att1/download/shot.png'] = function () {
            // Another reader of the task stored a file meanwhile and filled the quota.
            TaskAttachment::create(['task_id' => $this->task->id, 'file_path' => 'x', 'original_name' => 'x.png', 'mime' => 'image/png', 'size' => 1000, 'trello_attachment_id' => 'other']);

            return Http::response($this->png());
        };

        $record = $this->read();

        $this->assertSame(1, TaskAttachment::count());
        $this->assertSame('refused', collect($record['attachments'])->firstWhere('status', 'refused')['status'] ?? null);
        $this->assertSame([], Storage::disk('local')->allFiles('task-attachments/'.$this->task->id));
    }

    public function test_a_task_deleted_during_a_download_keeps_no_file(): void
    {
        $this->cardFile('att1', 'shot.png');
        $this->downloads['/attachments/att1/download/shot.png'] = function () {
            Task::query()->whereKey($this->task->id)->first()?->delete();

            return Http::response($this->png());
        };

        $this->read();

        $this->assertSame(0, TaskAttachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_files_the_card_dropped_are_removed_and_uploads_kept(): void
    {
        $this->cardFile('att1', 'old.png');
        $this->downloads['/attachments/att1/download/old.png'] = fn () => Http::response($this->png());
        $this->read();
        $pulled = TaskAttachment::sole();
        Storage::disk('local')->put("task-attachments/{$this->task->id}/mine.pdf", 'pdf');
        $upload = TaskAttachment::create(['task_id' => $this->task->id, 'file_path' => "task-attachments/{$this->task->id}/mine.pdf", 'original_name' => 'mine.pdf', 'mime' => 'application/pdf', 'size' => 3]);

        $this->card['attachments'] = [];
        $this->card['dateLastActivity'] = '2026-10-01T11:00:00.000Z';
        $this->task->update(['trello_activity_at' => '2026-10-01 11:00:00']);
        $record = $this->read();

        $this->assertNull(TaskAttachment::find($pulled->id));
        Storage::disk('local')->assertMissing($pulled->file_path);
        $this->assertNotNull(TaskAttachment::find($upload->id));
        Storage::disk('local')->assertExists($upload->file_path);
        $this->assertSame(['upload'], array_column($record['attachments'], 'from'));
    }

    /**
     * @return array<string, mixed>
     */
    private function read(bool $refresh = false): array
    {
        return app(TaskReader::class)->read($this->task->fresh() ?? $this->task, $refresh);
    }

    private function assertCardRequests(int $count): void
    {
        $this->assertSame($count, Http::recorded(fn (Request $r): bool => (string) parse_url($r->url(), PHP_URL_PATH) === '/1/cards/'.self::CARD)->count());
    }

    private function cardFile(string $id, string $name): void
    {
        $this->card['attachments'][] = [
            'id' => $id, 'name' => $name, 'bytes' => mb_strlen($this->png(), '8bit'), 'mimeType' => 'image/png', 'isUpload' => true,
            'url' => 'https://trello.com/1/cards/'.self::CARD."/attachments/{$id}/download/{$name}",
        ];
    }

    private function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }
}
