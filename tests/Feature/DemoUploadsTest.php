<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\DemoRows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The demo shares one login with every visitor: uploads are capped and the
 * nightly reset takes the files with the rows. Real installs keep both.
 */
final class DemoUploadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::disk('local')->put('task-attachments/1/a.png', 'x');
        Storage::disk('local')->put('client-documents/1/b.pdf', 'x');
        Storage::disk('local')->put('elsewhere/keep.txt', 'x');
    }

    public function test_the_reset_touches_no_file_outside_demo_mode(): void
    {
        config(['app.demo' => false]);

        $this->artisan('demo:reset')->assertFailed();

        Storage::disk('local')->assertExists('task-attachments/1/a.png');
        Storage::disk('local')->assertExists('client-documents/1/b.pdf');
    }

    public function test_the_reset_refuses_a_database_with_a_real_account_even_in_demo_mode(): void
    {
        config(['app.demo' => true]);
        $real = Account::create(['name' => 'Real agency']);

        $this->artisan('demo:reset')->assertFailed();

        Storage::disk('local')->assertExists('task-attachments/1/a.png');
        Storage::disk('local')->assertExists('client-documents/1/b.pdf');
        $this->assertTrue(Account::query()->whereKey($real->id)->exists());
    }

    public function test_demo_uploads_are_capped_per_file(): void
    {
        config(['app.demo' => true, 'app.demo_uploads.max_kb' => 100]);
        [$user, $task, $client] = $this->workspace();

        $this->actingAs($user)
            ->postJson("/tasks/{$task->id}/attachments", ['file' => UploadedFile::fake()->create('big.pdf', 101, 'application/pdf')])
            ->assertUnprocessable();
        $this->actingAs($user)
            ->post("/clients/{$client->id}/documents", ['file' => UploadedFile::fake()->create('big.pdf', 101, 'application/pdf')])
            ->assertSessionHasErrors('file');

        $this->actingAs($user)
            ->postJson("/tasks/{$task->id}/attachments", ['file' => UploadedFile::fake()->create('small.pdf', 99, 'application/pdf')])
            ->assertSuccessful();
    }

    public function test_demo_uploads_are_rate_limited_per_ip(): void
    {
        config(['app.demo' => true, 'app.demo_uploads.per_hour' => 2]);
        [$user, $task, $client] = $this->workspace();

        $upload = fn (string $ip) => $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson("/tasks/{$task->id}/attachments", ['file' => UploadedFile::fake()->image('shot.png')]);

        $upload('198.51.100.1')->assertSuccessful();
        $upload('198.51.100.1')->assertSuccessful();
        $upload('198.51.100.1')->assertStatus(429);
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])
            ->post("/clients/{$client->id}/documents", ['file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')])
            ->assertStatus(302)
            ->assertSessionHas('error');

        $upload('198.51.100.2')->assertSuccessful();
    }

    public function test_the_demo_takes_pictures_pdfs_and_text_and_nothing_a_visitor_could_be_harmed_by(): void
    {
        config(['app.demo' => true]);
        [$user, $task, $client] = $this->workspace();
        $toTask = fn (UploadedFile $file) => $this->actingAs($user)->postJson("/tasks/{$task->id}/attachments", ['file' => $file]);

        $toTask(UploadedFile::fake()->create('dump.zip', 10, 'application/zip'))->assertUnprocessable();
        $toTask(UploadedFile::fake()->create('offer.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))->assertUnprocessable();
        $toTask(UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'))->assertUnprocessable();
        $this->actingAs($user)
            ->post("/clients/{$client->id}/documents", ['file' => UploadedFile::fake()->create('sheet.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')])
            ->assertSessionHasErrors('file');

        $toTask(UploadedFile::fake()->image('shot.png'))->assertSuccessful();
        $toTask(UploadedFile::fake()->create('brief.pdf', 10, 'application/pdf'))->assertSuccessful();

        config(['app.demo' => false]);
        $toTask(UploadedFile::fake()->create('dump.zip', 10, 'application/zip'))->assertSuccessful();
    }

    public function test_a_full_demo_stores_no_file_for_a_row_it_will_refuse(): void
    {
        config(['app.demo' => true, 'app.demo_rows.tables' => ['task_attachments' => 0, 'client_documents' => 0]]);
        [$user, $task, $client] = $this->workspace();
        $before = Storage::disk('local')->allFiles();

        $this->actingAs($user)->postJson("/tasks/{$task->id}/attachments", ['file' => UploadedFile::fake()->image('shot.png')])->assertUnprocessable();
        $this->actingAs($user)->post("/clients/{$client->id}/documents", ['file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')])->assertSessionHas('error');

        $this->assertSame($before, Storage::disk('local')->allFiles());
    }

    public function test_real_installs_keep_their_size_cap_and_no_rate_limit(): void
    {
        config(['app.demo' => false, 'app.demo_uploads.max_kb' => 100, 'app.demo_uploads.per_hour' => 1]);
        [$user, $task] = $this->workspace();

        foreach (range(1, 3) as $_) {
            $this->actingAs($user)
                ->postJson("/tasks/{$task->id}/attachments", ['file' => UploadedFile::fake()->create('big.pdf', 5_000, 'application/pdf')])
                ->assertSuccessful();
        }
    }

    /** @return array{0: User, 1: Task, 2: Client} */
    private function workspace(): array
    {
        $account = Account::create(['name' => 'Acc', 'is_test' => true]);
        $user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $project = Project::create(['account_id' => $account->id, 'name' => 'P']);
        $client = Client::factory()->create(['account_id' => $account->id]);
        $task = Task::create(['project_id' => $project->id, 'name' => 'T']);
        DemoRows::record($account);

        return [$user, $task, $client];
    }
}
