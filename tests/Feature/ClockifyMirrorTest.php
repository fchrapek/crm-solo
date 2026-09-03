<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Integration;
use App\Models\Project;
use App\Services\Integrations\Clockify\ClockifyMirrorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ClockifyMirrorTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Client $client;

    private Project $project;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'Acc']);
        $this->client = Client::create([
            'account_id' => $this->account->id,
            'type' => 'business',
            'name' => 'ACME Sp. z o.o.',
        ]);
        $this->project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'name' => 'bakery.test',
        ]);
        $this->integration = Integration::create([
            'account_id' => $this->account->id,
            'provider' => 'clockify',
            'api_key' => 'fake-clockify-key',
            'is_enabled' => true,
            'settings' => ['email' => 'u@example.com'],
        ]);
    }

    public function test_push_time_entry_cascades_mirror_on_virgin_project(): void
    {
        // No pre-mirrored IDs — the first push must create the Clockify
        // client, then the project, then the time entry, and store all three
        // IDs back on the local rows.
        $entry = \App\Models\TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'source' => \App\Models\TimeEntry::SOURCE_TERMINAL_SESSION,
            'description' => 'First push',
            'start_time' => '2026-05-21 10:00:00',
            'end_time' => '2026-05-21 11:00:00',
            'duration_minutes' => 60,
            'billable' => true,
        ]);

        Http::fake([
            'api.clockify.me/api/v1/workspaces' => Http::response([
                ['id' => 'ws1', 'name' => 'My Workspace'],
            ], 200),
            'api.clockify.me/api/v1/workspaces/ws1/clients' => Http::response([
                'id' => 'clk-client-1',
                'name' => 'ACME Sp. z o.o.',
                'workspaceId' => 'ws1',
            ], 201),
            'api.clockify.me/api/v1/workspaces/ws1/projects' => Http::response([
                'id' => 'clk-project-1',
                'name' => 'bakery.test',
                'clientId' => 'clk-client-1',
                'workspaceId' => 'ws1',
            ], 201),
            'api.clockify.me/api/v1/workspaces/ws1/time-entries' => Http::response([
                'id' => 'clk-entry-1',
            ], 201),
        ]);

        $clockifyId = (new ClockifyMirrorService($this->integration))
            ->pushTimeEntry($entry->load('project.client'));

        $this->assertSame('clk-entry-1', $clockifyId);
        $this->assertSame('clk-client-1', $this->client->fresh()->clockify_client_id);
        $this->assertSame('clk-project-1', $this->project->fresh()->clockify_project_id);

        // Project create payload included the Clockify client ID as parent.
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.clockify.me/api/v1/workspaces/ws1/projects'
                && $request->method() === 'POST'
                && $request['name'] === 'bakery.test'
                && $request['clientId'] === 'clk-client-1';
        });
    }

    public function test_ensure_client_adopts_existing_clockify_client_on_409_style_response(): void
    {
        // The user created a client by hand in Clockify with the same name —
        // POST returns 400 with "already exists" and we look it up + adopt.
        // The wildcard on the clients URL is load-bearing: the lookup GET
        // appends a query string, so a literal key wouldn't match it.
        Http::fake([
            'api.clockify.me/api/v1/workspaces/ws1/clients*' => function ($request) {
                if ($request->method() === 'POST') {
                    return Http::response(['message' => 'Client with name already exists.'], 400);
                }

                return Http::response([
                    ['id' => 'clk-adopted', 'name' => 'ACME Sp. z o.o.'],
                ], 200);
            },
        ]);

        $adopted = (new ClockifyMirrorService($this->integration))
            ->ensureClockifyClient($this->client->fresh(), 'ws1');

        $this->assertSame('clk-adopted', $adopted);
        $this->assertSame('clk-adopted', $this->client->fresh()->clockify_client_id);
    }

    public function test_push_time_entry_creates_clockify_row_and_stores_id(): void
    {
        // Pre-mirror the client + project so the push goes directly to the
        // time-entries endpoint without the cascade.
        $this->client->update(['clockify_client_id' => 'clk-client-1']);
        $this->project->update(['clockify_project_id' => 'clk-project-1']);

        $entry = \App\Models\TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => null,
            'source' => \App\Models\TimeEntry::SOURCE_TERMINAL_SESSION,
            'description' => 'Hero refactor',
            'start_time' => '2026-05-21 10:00:00',
            'end_time' => '2026-05-21 11:00:00',
            'duration_minutes' => 60,
            'billable' => true,
        ]);

        Http::fake([
            'api.clockify.me/api/v1/workspaces' => Http::response([['id' => 'ws1']], 200),
            'api.clockify.me/api/v1/workspaces/ws1/time-entries' => Http::response([
                'id' => 'clk-entry-1',
                'description' => 'Hero refactor',
                'projectId' => 'clk-project-1',
            ], 201),
        ]);

        $clockifyId = (new ClockifyMirrorService($this->integration))
            ->pushTimeEntry($entry->load('project.client'));

        $this->assertSame('clk-entry-1', $clockifyId);
        $this->assertSame('clk-entry-1', $entry->fresh()->clockify_entry_id);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.clockify.me/api/v1/workspaces/ws1/time-entries'
                && $request->method() === 'POST'
                && $request['projectId'] === 'clk-project-1'
                && $request['description'] === 'Hero refactor'
                && $request['billable'] === true;
        });
    }

    public function test_push_skips_running_entries(): void
    {
        $entry = \App\Models\TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'source' => \App\Models\TimeEntry::SOURCE_TERMINAL_SESSION,
            'start_time' => now(),
            'end_time' => null,
            'duration_minutes' => 0,
            'billable' => true,
        ]);

        Http::fake();

        $result = (new ClockifyMirrorService($this->integration))
            ->pushTimeEntry($entry->load('project.client'));

        $this->assertNull($result);
        Http::assertNothingSent();
    }
}
