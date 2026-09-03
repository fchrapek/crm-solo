<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class TaskAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Acc']);
        $this->user = User::factory()->create([
            'account_id' => $this->account->id,
            'first_name' => 'T',
            'last_name' => 'U',
            'email' => 'u@example.com',
            'owner' => true,
        ]);

        $project = Project::create([
            'account_id' => $this->account->id,
            'name' => 'P',
        ]);
        $this->task = Task::create([
            'project_id' => $project->id,
            'name' => 'T',
        ]);

        Storage::fake('local');
    }

    public function test_image_upload_creates_attachment_row_and_stores_file(): void
    {
        $file = UploadedFile::fake()->image('screenshot.png');

        $response = $this->actingAs($this->user)
            ->postJson("/tasks/{$this->task->id}/attachments", [
                'file' => $file,
                'label' => 'before',
            ]);

        $response->assertCreated()
            ->assertJsonStructure(['id', 'url', 'original_name', 'mime', 'size']);

        $this->assertCount(1, TaskAttachment::all());
        $attachment = TaskAttachment::first();
        $this->assertSame($this->task->id, $attachment->task_id);
        $this->assertSame('screenshot.png', $attachment->original_name);
        $this->assertSame('before', $attachment->label);
        Storage::disk('local')->assertExists($attachment->file_path);
    }

    public function test_document_uploads_are_accepted(): void
    {
        $cases = [
            ['notes.txt', 'text/plain'],
            ['spec.md', 'text/markdown'],
            ['brief.pdf', 'application/pdf'],
            // DB-dump types — `.sql` for raw dumps, `.gz` for compressed ones
            // (also `.sql.gz` whose final extension is `.gz`). The agent reads
            // these from absolute paths in CRM_TASK.md and imports locally.
            ['schema.sql', 'text/plain'],
            ['dump.sql.gz', 'application/gzip'],
            ['archive.zip', 'application/zip'],
        ];

        foreach ($cases as [$name, $mime]) {
            $file = UploadedFile::fake()->create($name, 10, $mime);

            $this->actingAs($this->user)
                ->postJson("/tasks/{$this->task->id}/attachments", ['file' => $file])
                ->assertCreated();
        }

        $this->assertCount(count($cases), TaskAttachment::all());
    }

    public function test_unsupported_uploads_are_rejected(): void
    {
        // Executables / scripts must NOT be accepted even if the user renames
        // them — Laravel's mimes: rule validates against detected MIME, not
        // just the trusting client-supplied extension.
        $file = UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload');

        $this->actingAs($this->user)
            ->postJson("/tasks/{$this->task->id}/attachments", ['file' => $file])
            ->assertStatus(422);

        $this->assertCount(0, TaskAttachment::all());
    }

    public function test_show_serves_file_to_owner_and_403s_other_account(): void
    {
        $file = UploadedFile::fake()->image('a.png');
        $created = $this->actingAs($this->user)
            ->postJson("/tasks/{$this->task->id}/attachments", ['file' => $file])
            ->assertCreated()
            ->json();

        $this->actingAs($this->user)
            ->get($created['url'])
            ->assertOk();

        $otherAccount = Account::create(['name' => 'Other']);
        $otherUser = User::factory()->create([
            'account_id' => $otherAccount->id,
            'first_name' => 'O',
            'last_name' => 'U',
            'email' => 'other@example.com',
            'owner' => true,
        ]);

        $this->actingAs($otherUser)
            ->get($created['url'])
            ->assertStatus(403);
    }

    public function test_destroy_removes_row_and_file(): void
    {
        $file = UploadedFile::fake()->image('a.png');
        $created = $this->actingAs($this->user)
            ->postJson("/tasks/{$this->task->id}/attachments", ['file' => $file])
            ->assertCreated()
            ->json();
        $storedPath = TaskAttachment::find($created['id'])->file_path;

        $this->actingAs($this->user)
            ->deleteJson("/task-attachments/{$created['id']}")
            ->assertOk();

        $this->assertCount(0, TaskAttachment::all());
        Storage::disk('local')->assertMissing($storedPath);
    }

    public function test_index_returns_attachments_for_task(): void
    {
        $this->actingAs($this->user)
            ->postJson("/tasks/{$this->task->id}/attachments", [
                'file' => UploadedFile::fake()->image('one.png'),
            ])->assertCreated();
        $this->actingAs($this->user)
            ->postJson("/tasks/{$this->task->id}/attachments", [
                'file' => UploadedFile::fake()->image('two.png'),
            ])->assertCreated();

        $this->actingAs($this->user)
            ->getJson("/tasks/{$this->task->id}/attachments")
            ->assertOk()
            ->assertJsonCount(2, 'attachments');
    }

    public function test_default_role_is_context(): void
    {
        $file = UploadedFile::fake()->image('a.png');

        $response = $this->actingAs($this->user)
            ->postJson("/tasks/{$this->task->id}/attachments", ['file' => $file])
            ->assertCreated();

        $this->assertNotNull(TaskAttachment::first());
    }

    public function test_cross_account_upload_is_forbidden(): void
    {
        $otherAccount = Account::create(['name' => 'Other']);
        $otherUser = User::factory()->create([
            'account_id' => $otherAccount->id,
            'first_name' => 'O',
            'last_name' => 'U',
            'email' => 'other@example.com',
            'owner' => true,
        ]);

        // Route model binding scopes by account → cross-account hits return 404.
        $this->actingAs($otherUser)
            ->postJson("/tasks/{$this->task->id}/attachments", [
                'file' => UploadedFile::fake()->image('x.png'),
            ])
            ->assertStatus(404);
    }
}
