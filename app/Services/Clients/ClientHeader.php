<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Support\LegalName;
use Illuminate\Support\Facades\Auth;

final class ClientHeader
{
    public function __construct(
        private readonly ClientBillingMode $mode,
        private readonly ClientTasksQuery $tasks,
        private readonly HoursBank $bank,
    ) {}

    /**
     * @return array{id: int, name: string, short_name: string, billing_mode: string, billing: bool, retainer_terms: bool, is_archived: bool, is_pinned: bool, open_tasks_count: int, live_agent_minutes: int|null}
     */
    public function props(Client $client, ?string $mode = null): array
    {
        $mode ??= $this->mode->of($client);
        $billing = $client->account->shows('billing', Auth::user());
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
            'billing' => $billing,
            'retainer_terms' => $billing && $this->retainerTerms($client, $mode),
            'is_archived' => $client->deleted_at !== null,
            'is_pinned' => $client->is_pinned,
            'open_tasks_count' => $this->tasks->visibleOpenTasks($client)->count(),
            'live_agent_minutes' => $running?->start_time !== null
                ? (int) max(0, $running->start_time->diffInMinutes(now()))
                : null,
        ];
    }

    /** Overview's Hours tile has its Retainer button: the active month draws on a package and a position exists. */
    private function retainerTerms(Client $client, string $mode): bool
    {
        return $mode === ClientBillingMode::RETAINER
            && $client->retainers()->exists()
            && $this->bank->month($client, $this->bank->settlement($client)->activePeriod)->hasRetainer();
    }
}
