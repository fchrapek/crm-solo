<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class TimeEntriesTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Test Account']);
    }

    public function test_time_entry_belongs_to_account(): void
    {
        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'description' => 'Test work',
            'start_time' => now()->subHours(2),
            'end_time' => now(),
            'duration_minutes' => 120,
        ]);

        $this->assertSame($this->account->id, $entry->account->id);
    }

    public function test_time_entry_belongs_to_client(): void
    {
        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Test Client',
        ]);

        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'description' => 'Client work',
            'start_time' => now()->subHours(1),
            'duration_minutes' => 60,
        ]);

        $this->assertSame($client->id, $entry->client->id);
        $this->assertSame(1, $client->timeEntries()->count());
    }

    public function test_time_entry_belongs_to_project(): void
    {
        $project = Project::create([
            'account_id' => $this->account->id,
            'name' => 'Test Project',
        ]);

        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $project->id,
            'description' => 'Project work',
            'start_time' => now(),
            'duration_minutes' => 90,
        ]);

        $this->assertSame($project->id, $entry->project->id);
    }

    public function test_time_entry_casts(): void
    {
        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'description' => 'Cast test',
            'start_time' => '2026-04-01 09:00:00',
            'end_time' => '2026-04-01 11:00:00',
            'duration_minutes' => 120,
            'billable' => true,
            'tags' => ['development', 'frontend'],
        ]);

        $entry->refresh();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $entry->start_time);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $entry->end_time);
        $this->assertTrue($entry->billable);
        $this->assertIsArray($entry->tags);
        $this->assertSame(['development', 'frontend'], $entry->tags);
    }

    public function test_account_has_time_entries(): void
    {
        TimeEntry::create([
            'account_id' => $this->account->id,
            'description' => 'Entry 1',
            'start_time' => now(),
            'duration_minutes' => 60,
        ]);

        TimeEntry::create([
            'account_id' => $this->account->id,
            'description' => 'Entry 2',
            'start_time' => now(),
            'duration_minutes' => 30,
        ]);

        $this->assertSame(2, $this->account->timeEntries()->count());
    }

    public function test_total_duration_for_client(): void
    {
        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Hours Client',
        ]);

        TimeEntry::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'description' => 'Task A',
            'start_time' => now()->subDays(1),
            'duration_minutes' => 120,
        ]);

        TimeEntry::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'description' => 'Task B',
            'start_time' => now(),
            'duration_minutes' => 90,
        ]);

        $total = $client->timeEntries()->sum('duration_minutes');
        $this->assertSame(210, (int) $total);
    }

    public function test_imported_clockify_entries_still_list_on_the_client_page(): void
    {
        $user = User::factory()->create([
            'account_id' => $this->account->id,
            'first_name' => 'F',
            'last_name' => 'C',
            'email' => 'u@example.com',
            'owner' => true,
        ]);
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'Imported Client', 'type' => 'business']);
        (new TimeEntry)->forceFill([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'source' => TimeEntry::SOURCE_CLOCKIFY,
            'clockify_entry_id' => 'imported-entry-1',
            'description' => 'Pulled before the removal',
            'start_time' => '2026-07-08 09:00:00',
            'end_time' => '2026-07-08 10:00:00',
            'duration_minutes' => 60,
        ])->save();

        $this->actingAs($user)
            ->get("/clients/{$client->id}/edit")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('timeEntries.data.0.source', 'clockify')
                ->where('timeEntries.data.0.duration_minutes', 60)
                ->missing('timeEntries.data.0.is_in_clockify')
                ->missing('clockifyEnabled'));
    }
}
