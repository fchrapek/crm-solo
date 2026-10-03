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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_response_carries_the_baseline_headers(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
            ->assertHeaderMissing('Strict-Transport-Security');

        $this->get('/up')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_hsts_is_sent_over_https_outside_local(): void
    {
        config(['app.url' => 'https://crm-solo.test']);

        $this->get('https://crm-solo.test/login')
            ->assertHeader('Strict-Transport-Security', 'max-age='.(365 * 24 * 60 * 60));
    }

    public function test_hsts_is_never_sent_in_local(): void
    {
        config(['app.url' => 'https://crm-solo.test']);
        $this->app['env'] = 'local';

        $this->get('https://crm-solo.test/login')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_only_configured_proxies_can_set_the_client_ip(): void
    {
        Route::get('/_client-ip', fn () => request()->ip());
        $forwarded = ['HTTP_X_FORWARDED_FOR' => '203.0.113.9'];

        $this->withServerVariables($forwarded + ['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/_client-ip')->assertSeeText('203.0.113.9');

        $this->withServerVariables($forwarded + ['REMOTE_ADDR' => '192.168.1.20'])
            ->get('/_client-ip')->assertSeeText('192.168.1.20');

        config(['trustedproxy.proxies' => '192.168.1.0/24']);
        $this->withServerVariables($forwarded + ['REMOTE_ADDR' => '192.168.1.20'])
            ->get('/_client-ip')->assertSeeText('203.0.113.9');
    }

    public function test_early_refusals_carry_the_headers(): void
    {
        $this->assertBaseline($this->get('http://rebind.example/login')->assertStatus(400));

        $this->assertBaseline($this->call('POST', 'http://localhost/login', [], [], [], [
            'CONTENT_LENGTH' => '10000000000',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ])->assertStatus(413));

        $this->app->maintenanceMode()->activate([]);
        try {
            $this->assertBaseline($this->get('/login')->assertStatus(503));
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }

    public function test_redirects_json_and_downloads_carry_the_headers(): void
    {
        $account = Account::create(['name' => 'Acc']);
        $user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $task = Task::create(['project_id' => Project::create(['account_id' => $account->id, 'name' => 'P'])->id, 'name' => 'T']);
        Storage::fake('local');
        $upload = $this->actingAs($user)
            ->postJson("/tasks/{$task->id}/attachments", ['file' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain')])
            ->assertSuccessful();

        $this->assertBaseline($upload);
        $this->assertBaseline($this->actingAs($user)->get('/task-attachments/'.TaskAttachment::query()->sole()->id)->assertOk());
        $this->assertBaseline($this->actingAs($user)->get('/login')->assertRedirect());
        $this->assertBaseline($this->actingAs($user)->getJson('/time-entries/running')->assertOk());
    }

    private function assertBaseline(TestResponse $response): void
    {
        $response
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
    }
}
