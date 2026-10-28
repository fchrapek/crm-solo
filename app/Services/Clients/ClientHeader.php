<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Support\LegalName;

final class ClientHeader
{
    public function __construct(
        private readonly ClientBillingMode $mode,
        private readonly ClientTasksQuery $tasks,
    ) {}

    /**
     * @return array{id: int, name: string, short_name: string, billing_mode: string, is_archived: bool, is_pinned: bool, open_tasks_count: int, live_agent_minutes: int|null}
     */
    public function props(Client $client, ?string $mode = null): array
    {
        $mode ??= $this->mode->of($client);
        $running = $client->timeEntries()
            ->whereNull('end_time')
            ->whereNotNull('actor_via')
            ->oldest('start_time')
            ->first(['id', 'start_time']);

        return [
            'id' => $client->id,
            'name' => $client->name,
            'short_name' => LegalName::short($client->name),
            'billing_mode' => $mode,
            'billing' => $client->account->hasFeature('billing'),
            'is_archived' => $client->deleted_at !== null,
            'is_pinned' => $client->is_pinned,
            'open_tasks_count' => $this->tasks->visibleOpenTasks($client, $mode)->count(),
            'live_agent_minutes' => $running?->start_time !== null
                ? (int) max(0, $running->start_time->diffInMinutes(now()))
                : null,
        ];
    }
}
