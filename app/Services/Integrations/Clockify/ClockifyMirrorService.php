<?php

declare(strict_types=1);

namespace App\Services\Integrations\Clockify;

use App\Models\Client;
use App\Models\Integration;
use App\Models\Project;
use App\Models\TimeEntry;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Pushes CRM client/project rows into Clockify and stores the returned IDs
 * back on our records. `pushTimeEntry` auto-cascades the mirror — Clockify
 * needs the client to exist first, so we create client then project on
 * demand if they're not mirrored yet. Idempotent: the ensure* methods return
 * stored IDs without an API call when already linked.
 *
 * Pull direction lives in ClockifyService::syncTimeEntries.
 */
final class ClockifyMirrorService
{
    private const BASE_URL = 'https://api.clockify.me/api/v1';

    private PendingRequest $http;

    public function __construct(
        private readonly Integration $integration,
    ) {
        $apiKey = $this->integration->api_key;
        if (! $apiKey) {
            throw new RuntimeException('Clockify API key is not configured.');
        }
        $this->http = Http::baseUrl(self::BASE_URL)
            ->withHeaders(['X-Api-Key' => $apiKey])
            ->timeout(30);
    }

    /**
     * Returns existing `clockify_client_id` or creates a fresh Clockify client
     * matching this CRM client's name. Stored back on the CRM Client row so
     * subsequent calls skip the create.
     */
    public function ensureClockifyClient(Client $client, string $workspaceId): string
    {
        if ($client->clockify_client_id) {
            return $client->clockify_client_id;
        }

        $response = $this->http->post("/workspaces/{$workspaceId}/clients", [
            'name' => $client->name,
        ]);

        if ($response->status() === 400 && str_contains((string) $response->body(), 'already exists')) {
            // User created a matching client manually in Clockify before we
            // got here — look it up by name and adopt its ID rather than
            // failing the whole flow.
            $existing = $this->findClockifyClientByName($workspaceId, $client->name);
            if ($existing !== null) {
                $client->forceFill(['clockify_client_id' => $existing])->save();

                return $existing;
            }
        }

        if ($response->failed()) {
            throw new RuntimeException("Failed to create Clockify client: {$response->status()} {$response->body()}");
        }

        $clockifyId = (string) $response->json('id');
        if ($clockifyId === '') {
            throw new RuntimeException('Clockify client create returned no id.');
        }

        $client->forceFill(['clockify_client_id' => $clockifyId])->save();

        return $clockifyId;
    }

    /**
     * Same shape as ensureClockifyClient — idempotent, stores the returned ID.
     */
    public function ensureClockifyProject(Project $project, string $clockifyClientId, string $workspaceId): string
    {
        if ($project->clockify_project_id) {
            return $project->clockify_project_id;
        }

        $currency = $project->client?->currency ?: 'PLN';
        $this->ensureWorkspaceCurrency($workspaceId, $currency);

        $response = $this->http->post("/workspaces/{$workspaceId}/projects", [
            'name' => $project->name,
            'clientId' => $clockifyClientId,
            'isPublic' => false,
            'billable' => true,
            'hourlyRate' => ['amount' => 0, 'currency' => $currency],
        ]);

        if ($response->status() === 400 && str_contains((string) $response->body(), 'already exists')) {
            $existing = $this->findClockifyProjectByName($workspaceId, $project->name, $clockifyClientId);
            if ($existing !== null) {
                $project->forceFill(['clockify_project_id' => $existing])->save();

                return $existing;
            }
        }

        if ($response->failed()) {
            throw new RuntimeException("Failed to create Clockify project: {$response->status()} {$response->body()}");
        }

        $clockifyId = (string) $response->json('id');
        if ($clockifyId === '') {
            throw new RuntimeException('Clockify project create returned no id.');
        }

        $project->forceFill(['clockify_project_id' => $clockifyId])->save();

        return $clockifyId;
    }

    /**
     * Push a closed TimeEntry to Clockify and stash the returned ID back on
     * the row. Auto-cascades the mirror (client + project) if it hasn't been
     * done yet — first push on a virgin project triggers the full setup
     * without a separate user action.
     *
     * Returns the Clockify time-entry ID. Returns null if the entry isn't
     * pushable (already pushed, still running, missing project/client).
     */
    public function pushTimeEntry(TimeEntry $entry): ?string
    {
        if ($entry->clockify_entry_id !== null && $entry->clockify_entry_id !== '') {
            return $entry->clockify_entry_id;
        }
        if ($entry->end_time === null) {
            // Running entries can't be pushed — Clockify only accepts closed
            // ranges via this endpoint. We push on close().
            return null;
        }
        $entry->loadMissing('project.client');
        if ($entry->project === null || $entry->project->client === null) {
            return null;
        }

        $workspaceId = $this->resolveWorkspaceId();
        $clockifyClientId = $this->ensureClockifyClient($entry->project->client, $workspaceId);
        $clockifyProjectId = $this->ensureClockifyProject($entry->project, $clockifyClientId, $workspaceId);

        $response = $this->http->post("/workspaces/{$workspaceId}/time-entries", [
            'start' => $entry->start_time->toIso8601String(),
            'end' => $entry->end_time->toIso8601String(),
            'description' => $entry->description ?? '',
            'projectId' => $clockifyProjectId,
            'billable' => (bool) $entry->billable,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Failed to push time entry: {$response->status()} {$response->body()}");
        }

        $clockifyId = (string) $response->json('id');
        if ($clockifyId === '') {
            throw new RuntimeException('Clockify time-entry create returned no id.');
        }
        $entry->forceFill(['clockify_entry_id' => $clockifyId])->save();

        return $clockifyId;
    }

    /**
     * Push an edit to an already-mirrored TimeEntry. Used when the user
     * corrects an entry's start/end/description after Clockify has the
     * original (e.g. an overnight session needs trimming to real worked time).
     *
     * Returns true if the PUT succeeded, false if the entry isn't mirrored
     * yet (caller should fall back to pushTimeEntry to create), or throws on
     * network/API failure. The caller (UpdateTimeEntryInClockify action) is
     * responsible for catching + logging — Clockify outages must never block
     * a local edit.
     */
    public function updateTimeEntry(TimeEntry $entry): bool
    {
        if ($entry->clockify_entry_id === null || $entry->clockify_entry_id === '') {
            return false;
        }
        if ($entry->end_time === null) {
            // Same constraint as pushTimeEntry: running entries can't be
            // updated through this endpoint. Clockify v1 separates running vs.
            // finished entries into different APIs; once we close + push it,
            // we own it as a finished entry forever.
            return false;
        }
        $entry->loadMissing('project.client');
        if ($entry->project === null || $entry->project->client === null) {
            return false;
        }

        $workspaceId = $this->resolveWorkspaceId();
        // ensureClockifyProject is cheap when the project is already mirrored —
        // it returns the existing clockify_project_id without an API call.
        $clockifyClientId = $this->ensureClockifyClient($entry->project->client, $workspaceId);
        $clockifyProjectId = $this->ensureClockifyProject($entry->project, $clockifyClientId, $workspaceId);

        $response = $this->http->put(
            "/workspaces/{$workspaceId}/time-entries/{$entry->clockify_entry_id}",
            [
                'start' => $entry->start_time->toIso8601String(),
                'end' => $entry->end_time->toIso8601String(),
                'description' => $entry->description ?? '',
                'projectId' => $clockifyProjectId,
                'billable' => (bool) $entry->billable,
            ],
        );

        if ($response->failed()) {
            throw new RuntimeException("Failed to update time entry: {$response->status()} {$response->body()}");
        }

        return true;
    }

    /**
     * Update an already-mirrored project's hourly-rate currency in Clockify.
     * Cheaply idempotent: if the project isn't mirrored or already on the
     * target currency, returns false without an API call.
     */
    public function updateProjectCurrency(Project $project, string $currency): bool
    {
        if (! $project->clockify_project_id) {
            return false;
        }

        $workspaceId = $this->resolveWorkspaceId();
        $this->ensureWorkspaceCurrency($workspaceId, $currency);

        $response = $this->http->put(
            "/workspaces/{$workspaceId}/projects/{$project->clockify_project_id}",
            ['hourlyRate' => ['amount' => 0, 'currency' => $currency]],
        );

        if ($response->failed()) {
            throw new RuntimeException("Failed to update project currency: {$response->status()} {$response->body()}");
        }

        return true;
    }

    /**
     * Clockify v1 has no public POST /currencies endpoint — currencies are
     * managed in the Clockify UI. If the requested code isn't declared on
     * the workspace, Clockify silently falls back to the workspace default
     * on project create/update (PUT returns 200 but ignores the field).
     * We do a cheap GET check and log a warning so the symptom is visible
     * when a project lands on the wrong currency.
     */
    private function ensureWorkspaceCurrency(string $workspaceId, string $code): void
    {
        $response = $this->http->get("/workspaces/{$workspaceId}");
        if ($response->failed()) {
            return;
        }
        $codes = collect($response->json('currencies') ?? [])->pluck('code')->all();
        if (! in_array($code, $codes, true)) {
            logger()->warning('Clockify workspace is missing currency; add it in Clockify UI.', [
                'workspace_id' => $workspaceId,
                'requested' => $code,
                'available' => $codes,
            ]);
        }
    }

    private function findClockifyClientByName(string $workspaceId, string $name): ?string
    {
        $response = $this->http->get("/workspaces/{$workspaceId}/clients", [
            'name' => $name,
            'page-size' => 50,
        ]);
        if ($response->failed()) {
            return null;
        }
        foreach ($response->json() ?? [] as $row) {
            if (($row['name'] ?? null) === $name) {
                return (string) $row['id'];
            }
        }

        return null;
    }

    private function findClockifyProjectByName(string $workspaceId, string $name, string $clientId): ?string
    {
        $response = $this->http->get("/workspaces/{$workspaceId}/projects", [
            'name' => $name,
            'clients' => $clientId,
            'page-size' => 50,
        ]);
        if ($response->failed()) {
            return null;
        }
        foreach ($response->json() ?? [] as $row) {
            if (($row['name'] ?? null) === $name && ($row['clientId'] ?? null) === $clientId) {
                return (string) $row['id'];
            }
        }

        return null;
    }

    private function resolveWorkspaceId(): string
    {
        // The existing ClockifyService::syncTimeEntries uses workspaces[0] as
        // the default. Keep the same convention so push + pull operate on the
        // same workspace without a UI-level workspace picker. If the user has
        // multiple workspaces and this ever bites, we add a settings field.
        $response = $this->http->get('/workspaces');
        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch Clockify workspaces: '.$response->body());
        }
        $workspaces = $response->json();
        if (empty($workspaces)) {
            throw new RuntimeException('Clockify account has no workspaces.');
        }

        return (string) $workspaces[0]['id'];
    }
}
