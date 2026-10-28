<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Client;
use App\Models\ClientRetainer;
use App\Services\Clients\ClientBillingMode;
use App\Support\LocalCalendar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The client list for `crm clients` and MCP `clients`: who the clients are,
 * their type and stage, and a summary of today's retainer positions, in a
 * fixed number of queries whatever the list's length.
 */
final class ClientDirectory
{
    private const int DEFAULT_LIMIT = 100;

    private const int MAX_LIMIT = 500;

    public function __construct(private readonly ClientBillingMode $billing) {}

    /**
     * @param  array<string, mixed>|null  $retainer
     * @return array<string, mixed>
     */
    public static function item(Client $client, ?array $retainer, string $billingMode): array
    {
        return [
            'id' => (int) $client->id,
            'name' => (string) $client->name,
            'type' => $client->cooperation_type,
            'stage' => $client->lifecycle_stage,
            'billing_mode' => $billingMode,
            'pinned' => (bool) $client->is_pinned,
            'archived' => $client->trashed(),
            'retainer' => $retainer,
        ];
    }

    /**
     * @param  array{search?: ?string, stage?: ?string, type?: ?string, archived?: ?bool, limit?: ?int}  $filters
     * @return array<string, mixed>
     *
     * @throws AgentRefusal
     */
    public function list(int $accountId, array $filters): array
    {
        $stage = $this->choice($filters['stage'] ?? null, Client::LIFECYCLE_STAGES, 'stage');
        $type = $this->choice($filters['type'] ?? null, Client::COOPERATION_TYPES, 'type');
        $archived = (bool) ($filters['archived'] ?? false);
        $limit = $this->limit($filters['limit'] ?? null);
        $search = mb_trim((string) ($filters['search'] ?? ''));

        $query = Client::query()
            ->where('account_id', $accountId)
            ->when($archived, fn (Builder $q) => $q->withTrashed())
            ->when($stage !== null, fn (Builder $q) => $q->where('lifecycle_stage', $stage))
            ->when($type !== null, fn (Builder $q) => $q->where('cooperation_type', $type))
            ->when($search !== '', function (Builder $q) use ($search): void {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $q->where(fn (Builder $inner) => $inner
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('tax_id', 'like', $like));
            });

        $total = (clone $query)->count();
        $clients = $query->orderBy('name')->orderBy('id')->limit($limit)->get();
        $ids = $clients->pluck('id')->map(intval(...))->all();
        $modes = $this->billing->forIds($ids);
        $retainers = $this->retainers($ids);

        return [
            'total' => $total,
            'clients' => $clients->map(fn (Client $client): array => self::item(
                $client,
                $retainers->get($client->id),
                $modes[$client->id] ?? ClientBillingMode::ORDER,
            ))->values()->all(),
        ];
    }

    /**
     * Today's active positions per client: how many, their monthly hours and fees.
     *
     * @param  list<int>  $ids
     * @return Collection<int, array{positions: int, monthly_hours: float|null, monthly_fee: float|null, currency: string|null}>
     */
    private function retainers(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return ClientRetainer::query()
            ->whereIn('client_id', $ids)
            ->activeOn(LocalCalendar::todayDate())
            ->where('is_active', true)
            ->get(['client_id', 'monthly_hours', 'monthly_fee', 'currency'])
            ->groupBy('client_id')
            ->map(fn (Collection $positions): array => [
                'positions' => $positions->count(),
                'monthly_hours' => $positions->whereNotNull('monthly_hours')->isEmpty() ? null : (float) $positions->sum('monthly_hours'),
                'monthly_fee' => $positions->whereNotNull('monthly_fee')->isEmpty() ? null : (float) $positions->sum('monthly_fee'),
                'currency' => $positions->pluck('currency')->filter()->unique()->count() === 1 ? $positions->pluck('currency')->filter()->first() : null,
            ]);
    }

    /**
     * @param  list<string>  $valid
     */
    private function choice(?string $value, array $valid, string $field): ?string
    {
        $value = mb_strtolower(mb_trim((string) $value));
        if ($value === '' || $value === 'all') {
            return null;
        }
        if (! in_array($value, $valid, true)) {
            throw AgentRefusal::invalid("Unknown {$field} \"{$value}\".", ["valid_{$field}s" => $valid]);
        }

        return $value;
    }

    private function limit(mixed $limit): int
    {
        if ($limit === null || $limit === '') {
            return self::DEFAULT_LIMIT;
        }
        if (! is_numeric($limit) || (int) $limit < 1) {
            throw AgentRefusal::invalid('limit takes a whole number, 1 or more.');
        }

        return min((int) $limit, self::MAX_LIMIT);
    }
}
