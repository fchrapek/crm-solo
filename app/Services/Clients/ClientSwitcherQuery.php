<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientView;
use App\Models\User;
use App\Support\LegalName;
use Illuminate\Database\Eloquent\Collection;

/** The client switcher's short lists: the pinned clients, then the ones this user opened last. */
final class ClientSwitcherQuery
{
    public const int RECENT = 5;

    /** @return array{pinned: list<array{id: int, name: string}>, recent: list<array{id: int, name: string}>} */
    public function for(User $user): array
    {
        $pinned = Client::query()
            ->where('account_id', $user->account_id)
            ->where('is_pinned', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);

        $recentIds = ClientView::query()
            ->where('user_id', $user->id)
            ->whereIn('client_id', Client::query()->where('account_id', $user->account_id)->where('is_pinned', false)->select('id'))
            ->orderByDesc('viewed_at')
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->pluck('client_id');

        $recent = Client::query()->where('account_id', $user->account_id)->whereKey($recentIds)->get(['id', 'name'])
            ->sortBy(fn (Client $client): int|false => $recentIds->search($client->id));

        return ['pinned' => $this->rows($pinned), 'recent' => $this->rows($recent)];
    }

    /**
     * @param  Collection<int, Client>  $clients
     * @return list<array{id: int, name: string}>
     */
    private function rows(Collection $clients): array
    {
        return $clients->map(fn (Client $client): array => ['id' => $client->id, 'name' => LegalName::short($client->name)])->values()->all();
    }
}
