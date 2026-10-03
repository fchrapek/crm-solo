<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\ClientReportRevision;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Demo visitors share one login, so writes are budgeted per IP and per
 * request; a real install is unaffected.
 */
final class DemoWriteBudgetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $account = Account::create(['name' => 'Demo', 'is_test' => true]);
        $this->user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $this->client = $account->clients()->create(['name' => 'Przykładowa firma']);
        config(['app.demo' => true, 'app.demo_writes.per_minute' => 30, 'app.demo_writes.per_hour' => 40, 'app.demo_writes.max_text_kb' => 16]);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function oversizedSettings(): array
    {
        $nested = 'leaf';
        for ($i = 0; $i < 50; $i++) {
            $nested = [str_repeat('k', 400) => $nested];
        }

        return [
            'a long key with a null value' => [[str_repeat('k', 1_000_000) => null]],
            'an array of integers' => [range(1, 50_000)],
            'a deeply nested structure' => [$nested],
        ];
    }

    public function test_a_report_body_flood_is_refused_per_request_and_after_the_budget(): void
    {
        $report = $this->report();
        $this->travelTo(now()->addDay()->startOfHour());

        $this->save($report, str_repeat('a', 200_000))->assertSessionHas('error');
        $this->assertSame('seed', $report->fresh()->body_markdown);
        $this->assertSame(0, ClientReportRevision::query()->count());

        // A save a minute for 50 minutes: the hourly budget of 40, minus the
        // refused request above, lets 39 through.
        for ($i = 0; $i < 50; $i++) {
            $this->travel(1)->minutes();
            $this->save($report->fresh(), str_repeat($i % 2 === 0 ? 'a' : 'b', 15_000));
            if ($i < 39) {
                $this->assertNull(session('error'), "save {$i} was refused");
            }
        }

        $this->assertSame(39, ClientReportRevision::query()->count());
        $this->save($report->fresh(), 'one more')->assertSessionHas('error');
    }

    public function test_a_burst_is_capped_per_minute_and_another_visitor_is_unaffected(): void
    {
        foreach (range(1, 30) as $i) {
            $this->noteFrom('198.51.100.1', "note {$i}")->assertRedirect()->assertSessionMissing('error');
        }
        $this->noteFrom('198.51.100.1', 'too many')->assertSessionHas('error');

        $this->noteFrom('198.51.100.2', 'other visitor')->assertSessionMissing('error');
    }

    public function test_reading_login_and_logout_are_not_budgeted(): void
    {
        config(['app.demo_writes.per_minute' => 1, 'app.demo_writes.per_hour' => 1]);

        $this->noteFrom('198.51.100.1', 'first')->assertSessionMissing('error');
        foreach (range(1, 5) as $_) {
            $this->actingAs($this->user)->get('/')->assertOk();
        }
        $this->post('/logout')->assertRedirect();
        $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_outside_demo_mode_nothing_is_budgeted(): void
    {
        config(['app.demo' => false, 'app.demo_writes.per_minute' => 1, 'app.demo_writes.per_hour' => 1]);
        $report = $this->report();

        $this->save($report, str_repeat('a', 200_000))->assertSessionMissing('error');
        $this->save($report->fresh(), str_repeat('b', 200_000))->assertSessionMissing('error');

        $this->assertSame(str_repeat('b', 200_000), $report->fresh()->body_markdown);
    }

    /** @param array<array-key, mixed> $settings */
    #[DataProvider('oversizedSettings')]
    public function test_keys_numbers_and_nesting_count_against_the_cap(array $settings): void
    {
        $this->actingAs($this->user)
            ->putJson('/integrations/trello', ['is_enabled' => false, 'settings' => $settings])
            ->assertStatus(413);

        $this->assertSame(0, Integration::query()->count());
    }

    public function test_a_multipart_text_field_counts_against_the_cap(): void
    {
        Storage::fake('local');

        $this->actingAs($this->user)
            ->post("/clients/{$this->client->id}/documents", [
                'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
                'label' => str_repeat('x', 17 * 1024),
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, $this->client->documents()->count());

        $this->actingAs($this->user)
            ->post("/clients/{$this->client->id}/documents", [
                'file' => UploadedFile::fake()->create('doc.pdf', 1_000, 'application/pdf'),
                'label' => 'Umowa',
            ])
            ->assertSessionMissing('error');
        $this->assertSame(1, $this->client->documents()->count());
    }

    public function test_an_undeclared_integration_setting_is_never_stored_outside_the_demo(): void
    {
        config(['app.demo' => false]);

        $this->actingAs($this->user)
            ->put('/integrations/trello', ['is_enabled' => false, 'settings' => ['anything' => str_repeat('x', 100_000), 'client_conflicts' => ['forged']]])
            ->assertRedirect();

        $settings = Integration::query()->sole()->settings ?? [];
        $this->assertArrayNotHasKey('anything', $settings);
        $this->assertArrayNotHasKey('client_conflicts', $settings);
    }

    private function save(ClientReport $report, string $body)
    {
        return $this->actingAs($this->user)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])
            ->put("/clients/{$this->client->id}/reports/{$report->id}", ['body_markdown' => $body, 'version' => $report->version()]);
    }

    private function noteFrom(string $ip, string $note)
    {
        return $this->actingAs($this->user)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post("/clients/{$this->client->id}/lifecycle-events", ['note' => $note]);
    }

    private function report(): ClientReport
    {
        return $this->client->reports()->create([
            'account_id' => $this->client->account_id,
            'period_type' => 'month',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'contracted_hours' => 12,
            'actual_hours' => 3,
            'currency' => 'PLN',
            'composer_key' => 'structured_list',
            'body_markdown' => 'seed',
            'status' => ClientReport::STATUS_DRAFT,
            'generated_at' => now(),
        ]);
    }
}
