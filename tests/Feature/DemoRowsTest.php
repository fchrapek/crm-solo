<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskLink;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\DemoRows;
use App\Support\DemoWriteBudget;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Demo visitors share one account: it grows by a set number of rows past the seeded ones, its sample
 * records stay, and it is rebuilt within the hour once somebody has changed it.
 */
final class DemoRowsTest extends TestCase
{
    use RefreshDatabase;

    private const string FULL = 'The demo has reached its limit here. It starts fresh every hour.';

    private const string SAMPLE = 'The demo keeps its sample data. Only what you add can be deleted.';

    private Account $account;

    private User $visitor;

    private Client $client;

    private Project $project;

    private Task $task;

    private TimeEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Demo', 'is_test' => true]);
        $this->visitor = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->client = $this->account->clients()->create(['name' => 'Przykładowa firma']);
        $this->project = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Strona']);
        $this->task = Task::create(['account_id' => $this->account->id, 'project_id' => $this->project->id, 'name' => 'Makieta']);
        $this->entry = TimeEntry::create([
            'account_id' => $this->account->id, 'client_id' => $this->client->id, 'project_id' => $this->project->id, 'task_id' => $this->task->id,
            'title' => 'Makieta', 'start_time' => now()->subHours(2), 'end_time' => now()->subHour(), 'duration_minutes' => 60, 'source' => 'manual', 'billable' => true,
        ]);

        DemoRows::record($this->account);
        // A new client writes its first history line and its first project, which have room of their own.
        config(['app.demo' => true, 'app.demo_rows.per_table' => 2, 'app.demo_rows.tables' => ['client_lifecycle_events' => 50, 'projects' => 50]]);
    }

    public function test_a_visitor_adds_rows_up_to_the_room_and_is_then_refused(): void
    {
        $this->addClient('Pierwszy')->assertSessionMissing('error');
        $this->addClient('Drugi')->assertSessionMissing('error');

        $this->addClient('Trzeci')->assertRedirect('/clients')->assertSessionHas('error', self::FULL);
        $this->actingAs($this->visitor)->postJson('/clients', ['name' => 'Czwarty', 'type' => 'business'])->assertUnprocessable()->assertJsonPath('message', self::FULL);

        $this->assertSame(['Przykładowa firma', 'Pierwszy', 'Drugi'], Client::query()->orderBy('id')->pluck('name')->all());
    }

    public function test_a_row_in_the_bin_still_takes_its_place(): void
    {
        $this->addClient('Pierwszy');
        $this->addClient('Drugi');
        $this->actingAs($this->visitor)->delete('/clients/'.Client::query()->where('name', 'Drugi')->value('id'))->assertSessionMissing('error');

        $this->addClient('Trzeci')->assertSessionHas('error', self::FULL);
    }

    public function test_the_room_is_per_table_and_holds_on_every_way_in(): void
    {
        $add = fn (string $name) => $this->actingAs($this->visitor)->from('/tasks')->post("/projects/{$this->project->id}/tasks", ['name' => $name]);

        $add('Pierwsze')->assertSessionMissing('error');
        $this->actingAs($this->visitor)->from('/')->post('/day/add', ['name' => 'Drugie', 'project_id' => $this->project->id, 'date' => now()->toDateString(), 'request_id' => (string) str()->uuid()]);
        $this->assertSame(3, Task::query()->count());

        $add('Trzecie')->assertSessionHas('error', self::FULL);
        $this->assertSame(3, Task::query()->count());
        $this->addClient('Klient')->assertSessionMissing('error');
    }

    public function test_a_table_named_in_the_config_has_its_own_room(): void
    {
        config(['app.demo_rows.tables' => ['clients' => 3, 'client_lifecycle_events' => 50, 'projects' => 50]]);

        foreach (['Pierwszy', 'Drugi', 'Trzeci'] as $name) {
            $this->addClient($name)->assertSessionMissing('error');
        }
        $this->addClient('Czwarty')->assertSessionHas('error', self::FULL);
    }

    public function test_a_sample_record_cannot_be_deleted_and_a_visitors_own_can(): void
    {
        $this->actingAs($this->visitor)->from('/clients')->delete("/clients/{$this->client->id}")
            ->assertRedirect('/clients')->assertSessionHas('error', self::SAMPLE);
        $this->assertNull($this->client->fresh()->deleted_at);

        $this->addClient('Mój');
        $mine = Client::query()->where('name', 'Mój')->firstOrFail();
        $this->actingAs($this->visitor)->delete("/clients/{$mine->id}")->assertSessionMissing('error');
        $this->assertNotNull($mine->fresh()->deleted_at);
    }

    public function test_a_refused_delete_has_run_none_of_the_tasks_own_hooks(): void
    {
        $this->actingAs($this->visitor)->deleteJson("/tasks/{$this->task->id}")->assertUnprocessable()->assertJsonPath('message', self::SAMPLE);

        $this->assertNotNull($this->task->fresh());
        $this->assertSame($this->task->id, $this->entry->fresh()->task_id);
    }

    public function test_a_sample_project_keeps_its_tasks(): void
    {
        $this->actingAs($this->visitor)->from('/clients')->delete("/projects/{$this->project->id}")->assertSessionHas('error', self::SAMPLE);

        $this->assertNotNull($this->project->fresh());
        $this->assertNotNull($this->task->fresh());
        $this->assertSame($this->task->id, $this->entry->fresh()->task_id);
    }

    public function test_work_done_for_nobody_signed_in_is_not_held(): void
    {
        foreach (range(1, 5) as $i) {
            $this->account->clients()->create(['name' => "Klient {$i}"]);
        }
        $this->client->delete();

        $this->assertSame(5, Client::query()->count());
    }

    public function test_outside_the_demo_nothing_is_held(): void
    {
        config(['app.demo' => false]);

        foreach (range(1, 4) as $i) {
            $this->addClient("Klient {$i}")->assertSessionMissing('error');
        }
        $this->actingAs($this->visitor)->delete("/clients/{$this->client->id}")->assertSessionMissing('error');

        $this->assertNotNull($this->client->fresh()->deleted_at);
    }

    public function test_a_demo_with_no_baseline_has_no_room_and_loses_nothing(): void
    {
        Setting::query()->where('scope', DemoRows::SCOPE)->delete();

        $this->addClient('Pierwszy')->assertSessionHas('error', self::FULL);
        $this->actingAs($this->visitor)->delete("/clients/{$this->client->id}")->assertSessionHas('error', self::SAMPLE);
    }

    public function test_the_seeder_records_the_last_id_of_every_table_it_filled(): void
    {
        // The seeder writes its sample attachments to the disk.
        Storage::fake('local');
        config(['app.demo' => false]);
        Account::query()->delete();
        $this->seed(DemoSeeder::class);

        $baseline = Setting::query()->where('scope', DemoRows::SCOPE)->sole();

        $this->assertSame((int) Client::withTrashed()->max('id'), $baseline->data['clients']);
        $this->assertSame((int) Task::query()->max('id'), $baseline->data['tasks']);
        $this->assertSame($baseline->id, $baseline->data['settings']);
    }

    public function test_the_hourly_reset_waits_for_a_visitors_write(): void
    {
        DemoWriteBudget::takeChanged();

        $this->artisan('demo:reset --if-changed')->expectsOutputToContain('No visitor has changed the demo')->assertSuccessful();
        $this->assertNotNull($this->client->fresh());

        $this->actingAs($this->visitor)->get('/clients')->assertOk();
        $this->assertFalse(DemoWriteBudget::takeChanged());

        $this->addClient('Pierwszy');
        $this->assertTrue(DemoWriteBudget::takeChanged());
        $this->assertFalse(DemoWriteBudget::takeChanged());
    }

    public function test_a_refusal_an_hour_will_not_change_is_not_tried_again(): void
    {
        Account::create(['name' => 'Real agency', 'is_test' => false]);
        DemoWriteBudget::markChanged();

        $this->artisan('demo:reset')->assertFailed();

        $this->assertNotNull($this->client->fresh());
        $this->assertFalse(DemoWriteBudget::takeChanged());
    }

    public function test_a_second_reset_does_not_start_while_one_runs(): void
    {
        DemoWriteBudget::markChanged();
        $running = Cache::lock('demo-reset', 60);
        $running->get();

        $this->artisan('demo:reset')->expectsOutputToContain('Another reset is running')->assertSuccessful();

        $this->assertNotNull($this->client->fresh());
        $this->assertTrue(DemoWriteBudget::takeChanged());
        $running->release();
    }

    public function test_a_seeded_link_and_a_seeded_file_survive_a_refused_delete(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put("task-attachments/{$this->task->id}/brief.pdf", 'pdf');
        $file = TaskAttachment::create(['task_id' => $this->task->id, 'file_path' => "task-attachments/{$this->task->id}/brief.pdf", 'original_name' => 'brief.pdf', 'mime' => 'application/pdf', 'size' => 3]);
        $link = TaskLink::create(['task_id' => $this->task->id, 'url' => 'https://example.test/brief', 'label' => 'Brief']);
        DemoRows::record($this->account);

        $this->actingAs($this->visitor)->deleteJson("/task-attachments/{$file->id}")->assertUnprocessable()->assertJsonPath('message', self::SAMPLE);
        $this->actingAs($this->visitor)->deleteJson("/task-links/{$link->id}")->assertUnprocessable();

        Storage::disk('local')->assertExists("task-attachments/{$this->task->id}/brief.pdf");
        $this->assertNotNull($file->fresh());
        $this->assertNotNull($link->fresh());
    }

    public function test_a_refused_save_comes_back_as_an_error_of_the_form(): void
    {
        $this->addClient('Pierwszy');
        $this->addClient('Drugi');

        $this->addClient('Trzeci')->assertSessionHasErrors(['demo' => self::FULL]);
    }

    public function test_the_demo_resets_every_night_and_every_hour_on_a_change(): void
    {
        $commands = fn (): array => collect($this->schedule()->events())->map(fn (Event $event): string => $event->expression.' '.str($event->command)->after('artisan')->replace("'", '')->trim())->filter(fn (string $line): bool => str_contains($line, 'demo:reset'))->values()->all();

        $this->assertSame(['30 3 * * * demo:reset', '0 * * * * demo:reset --if-changed'], $commands());

        config(['app.demo' => false]);
        $this->assertSame([], $commands());
    }

    private function addClient(string $name): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->visitor)->from('/clients')->post('/clients', ['name' => $name, 'type' => 'business']);
    }

    private function schedule(): Schedule
    {
        ScheduleFacade::swap($schedule = new Schedule);
        require base_path('routes/console.php');

        return $schedule;
    }
}
