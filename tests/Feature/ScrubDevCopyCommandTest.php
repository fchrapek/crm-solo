<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\IntakeForm;
use App\Models\Integration;
use App\Models\LeadIntake;
use App\Models\MessageRule;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ScrubDevCopyCommandTest extends TestCase
{
    use RefreshDatabase;

    private Account $kept;

    private Account $other;

    /** @var array<string, int> */
    private array $keptIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['app.demo' => false, 'app.demo_reset_allowed' => true]);

        $this->kept = Account::factory()->create();
        $this->other = Account::factory()->create();
        $this->keptIds = $this->seedAccount($this->kept, 'kept');
        $this->seedAccount($this->other, 'other');
    }

    public function test_nothing_of_another_account_survives_the_scrub_and_the_kept_account_is_untouched(): void
    {
        $this->artisan('dev:scrub', ['--keep-account' => $this->kept->id])->assertSuccessful();

        $this->assertSame([$this->kept->id], DB::table('accounts')->pluck('id')->all());
        foreach ($this->tablesWithAccountId() as $table) {
            $this->assertSame(0, DB::table($table)->where('account_id', '!=', $this->kept->id)->count(), "{$table} keeps rows of another account");
        }
        $this->assertSame([$this->keptIds['task']], Task::query()->pluck('id')->all());
        $this->assertSame([$this->keptIds['attachment']], TaskAttachment::query()->pluck('id')->all());
        $this->assertSame([$this->keptIds['repository']], Repository::query()->pluck('id')->all());
        $this->assertSame([$this->keptIds['client']], Client::query()->pluck('id')->all());
        $this->assertSame(1, TimeEntry::query()->count());
    }

    public function test_the_files_of_the_deleted_rows_go_and_the_kept_files_stay(): void
    {
        $this->artisan('dev:scrub', ['--keep-account' => $this->kept->id])->assertSuccessful();

        Storage::disk('local')->assertExists('task-attachments/kept/brief.pdf');
        Storage::disk('local')->assertExists('client-documents/kept/contract.pdf');
        Storage::disk('local')->assertMissing('task-attachments/other/brief.pdf');
        Storage::disk('local')->assertMissing('client-documents/other/contract.pdf');
        Storage::disk('local')->assertMissing('task-attachments/orphan/brief.pdf');
    }

    public function test_an_upload_no_kept_row_points_to_goes_with_its_empty_folder(): void
    {
        Storage::disk('local')->put('task-attachments/stray/left-over.pdf', 'pdf');

        $this->artisan('dev:scrub', ['--keep-account' => $this->kept->id])
            ->expectsOutputToContain('Deleted 4 files')
            ->assertSuccessful();

        Storage::disk('local')->assertMissing('task-attachments/stray/left-over.pdf');
        $this->assertSame(['task-attachments/kept'], Storage::disk('local')->directories('task-attachments'));
    }

    public function test_a_scrub_that_fails_deletes_no_row_and_no_file(): void
    {
        Schema::create('restricting_rows', function (Blueprint $table): void {
            $table->unsignedInteger('client_id');
            $table->foreign('client_id')->references('id')->on('clients')->restrictOnDelete();
        });
        DB::table('restricting_rows')->insert(['client_id' => Client::query()->where('account_id', $this->other->id)->value('id')]);

        $this->artisan('dev:scrub', ['--keep-account' => $this->kept->id])->assertFailed();

        $this->assertSame(2, Account::query()->count());
        $this->assertTrue(Task::query()->where('name', 'other task')->exists());
        Storage::disk('local')->assertExists('task-attachments/other/brief.pdf');
        Storage::disk('local')->assertExists('client-documents/other/contract.pdf');
    }

    public function test_a_task_whose_project_is_already_gone_is_removed_too(): void
    {
        $this->assertTrue(Task::query()->where('name', 'orphan task')->exists());

        $this->artisan('dev:scrub', ['--keep-account' => $this->kept->id])->assertSuccessful();

        $this->assertFalse(Task::query()->where('name', 'orphan task')->exists());
    }

    public function test_every_token_goes_and_every_integration_is_switched_off_without_its_key(): void
    {
        $this->artisan('dev:scrub', ['--keep-account' => $this->kept->id])->assertSuccessful();

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $integration = Integration::query()->sole();
        $this->assertFalse((bool) $integration->is_enabled);
        $this->assertNull(DB::table('integrations')->value('api_key'));
    }

    public function test_pins_existing_behaviour_the_integration_scrub_also_switches_off_the_account_sender_without_its_password(): void
    {
        Integration::create(['account_id' => $this->kept->id, 'provider' => Integration::SMTP, 'is_enabled' => true, 'api_key' => 'smtp-password', 'settings' => ['host' => 'smtp.example.test']]);

        $this->artisan('dev:scrub', ['--keep-account' => $this->kept->id])->assertSuccessful();

        $sender = Integration::query()->where('provider', Integration::SMTP)->sole();
        $this->assertFalse($sender->is_enabled);
        $this->assertNull(DB::table('integrations')->where('id', $sender->id)->value('api_key'));
    }

    public function test_every_form_is_switched_off_without_its_secrets_and_waiting_notices_are_skipped(): void
    {
        $form = IntakeForm::create([
            'account_id' => $this->kept->id, 'name' => 'Drawer', 'public_key' => IntakeForm::newPublicKey(),
            'allowed_origins' => ['https://www.example.test'], 'pipeline' => 'filipchrapek', 'source' => 'www-form',
            'turnstile_secret' => 'turnstile', 'webhook_secret_hash' => hash('sha256', 'webhook'), 'is_active' => true,
        ]);
        $waiting = LeadIntake::create(['account_id' => $this->kept->id, 'intake_form_id' => $form->id, 'idempotency_key' => 'a', 'fingerprint' => str_repeat('0', 64)]);
        $sent = LeadIntake::create(['account_id' => $this->kept->id, 'intake_form_id' => $form->id, 'idempotency_key' => 'b', 'fingerprint' => str_repeat('0', 64), 'notice_state' => 'sent']);

        $this->artisan('dev:scrub', ['--keep-account' => $this->kept->id])->assertSuccessful();

        $row = DB::table('intake_forms')->where('id', $form->id)->first();
        $this->assertSame([0, null, null], [(int) $row->is_active, $row->turnstile_secret, $row->webhook_secret_hash]);
        $this->assertSame(LeadIntake::SKIPPED, $waiting->refresh()->notice_state);
        $this->assertSame('sent', $sent->refresh()->notice_state);
    }

    public function test_it_refuses_without_the_staging_flag_and_deletes_nothing(): void
    {
        config(['app.demo_reset_allowed' => false]);

        $this->artisan('dev:scrub', ['--keep-account' => $this->kept->id])->assertFailed();

        $this->assertSame(2, Account::query()->count());
        Storage::disk('local')->assertExists('task-attachments/other/brief.pdf');
    }

    public function test_it_refuses_on_the_public_demo(): void
    {
        config(['app.demo' => true]);

        $this->artisan('dev:scrub', ['--keep-account' => $this->kept->id])->assertFailed();

        $this->assertSame(2, Account::query()->count());
    }

    public function test_it_refuses_an_account_that_does_not_exist_or_is_not_given(): void
    {
        $this->artisan('dev:scrub', ['--keep-account' => 999])->assertFailed();
        $this->artisan('dev:scrub')->assertFailed();

        $this->assertSame(2, Account::query()->count());
    }

    /** @return array<string, int> */
    private function seedAccount(Account $account, string $tag): array
    {
        $user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $client = Client::factory()->create(['account_id' => $account->id, 'name' => "{$tag} client"]);
        $project = Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => "{$tag} site"]);
        $repository = Repository::create(['project_id' => $project->id, 'name' => 'r', 'local_path' => "/tmp/{$tag}", 'provider' => 'local']);
        $task = Task::create(['project_id' => $project->id, 'name' => "{$tag} task", 'source' => 'manual']);
        $attachment = TaskAttachment::create([
            'task_id' => $task->id, 'file_path' => "task-attachments/{$tag}/brief.pdf",
            'original_name' => 'brief.pdf', 'mime' => 'application/pdf', 'size' => 3,
        ]);
        Storage::disk('local')->put("task-attachments/{$tag}/brief.pdf", 'pdf');
        ClientDocument::create([
            'account_id' => $account->id, 'client_id' => $client->id, 'file_path' => "client-documents/{$tag}/contract.pdf",
            'original_name' => 'contract.pdf', 'mime' => 'application/pdf', 'size' => 3, 'category' => 'contract',
        ]);
        Storage::disk('local')->put("client-documents/{$tag}/contract.pdf", 'pdf');
        TimeEntry::create([
            'account_id' => $account->id, 'project_id' => $project->id, 'client_id' => $client->id, 'task_id' => $task->id,
            'start_time' => now()->subHour(), 'end_time' => now(), 'duration_minutes' => 60, 'source' => 'manual', 'billable' => true,
        ]);
        Integration::create(['account_id' => $account->id, 'provider' => "{$tag}-provider", 'is_enabled' => true, 'api_key' => "{$tag}-key"]);
        $template = MessageTemplate::create(['account_id' => $account->id, 'key' => 't', 'name' => 'T', 'body' => 'Hello']);
        MessageRule::create([
            'account_id' => $account->id, 'key' => 'r', 'template_id' => $template->id,
            'trigger_type' => MessageRule::FIELD_VALUE, 'trigger_config' => ['field' => 'x', 'values' => null],
        ]);
        $user->createToken('agent', ['read'], now()->addDays(30));

        if ($tag === 'other') {
            $orphan = Task::create(['project_id' => 999999, 'name' => 'orphan task', 'source' => 'manual']);
            TaskAttachment::create([
                'task_id' => $orphan->id, 'file_path' => 'task-attachments/orphan/brief.pdf',
                'original_name' => 'brief.pdf', 'mime' => 'application/pdf', 'size' => 3,
            ]);
            Storage::disk('local')->put('task-attachments/orphan/brief.pdf', 'pdf');
        }

        return ['client' => $client->id, 'task' => $task->id, 'attachment' => $attachment->id, 'repository' => $repository->id];
    }

    /** @return list<string> */
    private function tablesWithAccountId(): array
    {
        return array_values(array_filter(
            Schema::getTableListing(Schema::getCurrentSchemaName(), schemaQualified: false),
            fn (string $table): bool => Schema::hasColumn($table, 'account_id'),
        ));
    }
}
