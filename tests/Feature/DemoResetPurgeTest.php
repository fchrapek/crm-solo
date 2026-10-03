<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * migrate:fresh cannot run inside RefreshDatabase's transaction on SQLite, so
 * this class starts from an empty in-memory database and builds it itself.
 */
final class DemoResetPurgeTest extends TestCase
{
    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['app.demo' => true]);

        $this->outside = sys_get_temp_dir().'/demo-reset-outside-'.bin2hex(random_bytes(4));
        File::makeDirectory($this->outside);
        file_put_contents($this->outside.'/sentinel.txt', 'keep me');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->outside);

        parent::tearDown();
    }

    public function test_the_reset_deletes_uploaded_files_and_nothing_else(): void
    {
        $this->demoDatabase();
        Storage::disk('local')->put('task-attachments/1/a.png', 'x');
        Storage::disk('local')->put('client-documents/1/b.pdf', 'x');
        Storage::disk('local')->put('elsewhere/keep.txt', 'x');

        $this->artisan('demo:reset')->assertSuccessful();

        Storage::disk('local')->assertMissing('task-attachments/1/a.png');
        Storage::disk('local')->assertMissing('client-documents/1/b.pdf');
        Storage::disk('local')->assertExists('elsewhere/keep.txt');
        $this->assertSame(User::DEMO_EMAIL, User::query()->sole()->email);
    }

    public function test_a_symlinked_upload_directory_is_refused_and_its_target_survives(): void
    {
        $this->demoDatabase();
        Storage::disk('local')->put('client-documents/1/b.pdf', 'x');
        symlink($this->outside, Storage::disk('local')->path('task-attachments'));

        $this->artisan('demo:reset')->assertFailed();

        $this->assertFileExists($this->outside.'/sentinel.txt');
        Storage::disk('local')->assertExists('client-documents/1/b.pdf');
    }

    public function test_a_link_inside_an_upload_directory_is_removed_without_following_it(): void
    {
        $this->demoDatabase();
        Storage::disk('local')->put('task-attachments/1/a.png', 'x');
        symlink($this->outside, Storage::disk('local')->path('task-attachments/1/escape'));

        $this->artisan('demo:reset')->assertSuccessful();

        $this->assertFileExists($this->outside.'/sentinel.txt');
        // The reseed writes its own attachment, so the directory itself comes back.
        Storage::disk('local')->assertMissing('task-attachments/1/a.png');
        $this->assertFalse(is_link(Storage::disk('local')->path('task-attachments/1/escape')));
    }

    public function test_a_disk_root_outside_the_storage_folder_is_refused(): void
    {
        $this->demoDatabase();
        config(['filesystems.disks.local.root' => $this->outside]);
        Storage::forgetDisk('local');
        mkdir($this->outside.'/task-attachments');
        file_put_contents($this->outside.'/task-attachments/sentinel.txt', 'keep me');

        $this->artisan('demo:reset')->assertFailed();

        $this->assertFileExists($this->outside.'/task-attachments/sentinel.txt');
    }

    public function test_an_empty_database_is_a_first_install_and_gets_seeded(): void
    {
        Storage::disk('local')->put('task-attachments/1/a.png', 'x');

        $this->artisan('demo:reset')->assertSuccessful();

        Storage::disk('local')->assertMissing('task-attachments/1/a.png');
        $this->assertSame(User::DEMO_EMAIL, User::query()->sole()->email);
    }

    public function test_an_empty_database_still_gets_the_purge_checks(): void
    {
        symlink($this->outside, Storage::disk('local')->path('task-attachments'));

        $this->artisan('demo:reset')->assertFailed();

        $this->assertFileExists($this->outside.'/sentinel.txt');
        $this->assertFalse(Schema::hasTable('accounts'));
    }

    public function test_tables_without_an_accounts_table_are_refused(): void
    {
        Schema::create('something_else', fn (Blueprint $table) => $table->id());
        Storage::disk('local')->put('task-attachments/1/a.png', 'x');

        $this->artisan('demo:reset')->assertFailed();

        Storage::disk('local')->assertExists('task-attachments/1/a.png');
        $this->assertTrue(Schema::hasTable('something_else'));
    }

    public function test_a_non_test_account_is_refused(): void
    {
        $this->demoDatabase();
        Account::create(['name' => 'Real agency']);

        $this->artisan('demo:reset')->assertFailed();

        $this->assertSame(2, Account::query()->count());
    }

    public function test_a_staging_stack_may_reset_its_fictional_data_without_demo_mode(): void
    {
        config(['app.demo' => false, 'app.demo_reset_allowed' => true]);
        $this->demoDatabase();

        $this->artisan('demo:reset')->assertSuccessful();

        $this->assertSame(User::DEMO_EMAIL, User::query()->sole()->email);
    }

    public function test_a_staging_stack_still_refuses_to_reset_a_real_account(): void
    {
        config(['app.demo' => false, 'app.demo_reset_allowed' => true]);
        $this->demoDatabase();
        Account::create(['name' => 'Real agency']);

        $this->artisan('demo:reset')->assertFailed();

        $this->assertSame(2, Account::query()->count());
    }

    private function demoDatabase(): void
    {
        $this->artisan('migrate')->assertSuccessful();
        Account::create(['name' => 'Demo', 'is_test' => true]);
    }
}
