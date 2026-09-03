<?php

declare(strict_types=1);

namespace App\Services\Integrations\Clockify;

use App\Models\Integration;
use App\Models\Project;
use App\Models\TimeEntry;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class ClockifyService
{
    private const BASE_URL = 'https://api.clockify.me/api/v1';

    private PendingRequest $http;

    public function __construct(
        private readonly Integration $integration
    ) {
        $apiKey = $this->integration->api_key;

        if (! $apiKey) {
            throw new RuntimeException('Clockify API key is not configured.');
        }

        $this->http = Http::baseUrl(self::BASE_URL)
            ->withHeaders(['X-Api-Key' => $apiKey])
            ->timeout(30);
    }

    public function testConnection(): bool
    {
        $response = $this->http->get('/user');

        return $response->ok();
    }

    public function fetchWorkspaces(): array
    {
        $response = $this->http->get('/workspaces');

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch Clockify workspaces: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Fetch time entries for a workspace.
     */
    public function fetchTimeEntries(string $workspaceId, string $userId, ?string $start = null, ?string $end = null): array
    {
        $params = [
            'page-size' => 100,
            'hydrated' => 'true',
        ];

        if ($start) {
            $params['start'] = $start;
        }

        if ($end) {
            $params['end'] = $end;
        }

        $allEntries = [];
        $page = 1;

        do {
            $params['page'] = $page;

            $response = $this->http->get("/workspaces/{$workspaceId}/user/{$userId}/time-entries", $params);

            if ($response->failed()) {
                throw new RuntimeException('Failed to fetch Clockify time entries: '.$response->body());
            }

            $entries = $response->json();
            $allEntries = array_merge($allEntries, $entries);
            $page++;
        } while (count($entries) === 100);

        return $allEntries;
    }

    /**
     * Sync time entries for a workspace into the local database.
     */
    public function syncTimeEntries(int $accountId, ?string $afterDate = null): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'errors' => 0];

        $workspaces = $this->fetchWorkspaces();

        if (empty($workspaces)) {
            return $stats;
        }

        $userResponse = $this->http->get('/user');

        if ($userResponse->failed()) {
            throw new RuntimeException('Failed to fetch Clockify user: '.$userResponse->body());
        }

        $userId = $userResponse->json('id');

        // Use first workspace (most common setup)
        $workspaceId = $workspaces[0]['id'];

        $start = $afterDate
            ? Carbon::parse($afterDate)->toIso8601String()
            : ($this->integration->last_synced_at?->toIso8601String());

        $entries = $this->fetchTimeEntries($workspaceId, $userId, $start);

        foreach ($entries as $entry) {
            try {
                $this->syncEntry($entry, $accountId);
                // Check if it was created or updated by re-querying
                $existing = TimeEntry::where('clockify_entry_id', $entry['id'])->first();
                $existing?->wasRecentlyCreated ? $stats['created']++ : $stats['updated']++;
            } catch (Exception $e) {
                Log::warning('Failed to sync Clockify entry', [
                    'entry_id' => $entry['id'],
                    'error' => $e->getMessage(),
                ]);
                $stats['errors']++;
            }
        }

        $this->integration->update(['last_synced_at' => now()]);

        return $stats;
    }

    private function syncEntry(array $entry, int $accountId): void
    {
        $startTime = Carbon::parse($entry['timeInterval']['start']);
        $endTime = isset($entry['timeInterval']['end']) ? Carbon::parse($entry['timeInterval']['end']) : null;
        $durationSeconds = $entry['timeInterval']['duration'] ?? null;

        // Parse ISO 8601 duration (PT1H30M15S)
        $durationMinutes = 0;
        if ($durationSeconds && preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $durationSeconds, $m)) {
            $durationMinutes = (int) ($m[1] ?? 0) * 60 + (int) ($m[2] ?? 0) + (int) ceil((int) ($m[3] ?? 0) / 60);
        }

        $projectId = null;
        if (! empty($entry['project'])) {
            $project = Project::where('account_id', $accountId)
                ->where('name', 'like', '%'.$entry['project']['name'].'%')
                ->first();
            $projectId = $project?->id;
        }

        $clientId = null;
        if ($projectId) {
            $clientId = Project::find($projectId)?->client_id;
        }

        TimeEntry::updateOrCreate(
            [
                'account_id' => $accountId,
                'clockify_entry_id' => $entry['id'],
            ],
            [
                'project_id' => $projectId,
                'client_id' => $clientId,
                'description' => $entry['description'] ?? null,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration_minutes' => $durationMinutes,
                'billable' => $entry['billable'] ?? true,
                'tags' => ! empty($entry['tags']) ? collect($entry['tags'])->pluck('name')->all() : null,
            ]
        );
    }
}
