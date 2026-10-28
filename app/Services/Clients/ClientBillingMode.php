<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientMonthClosure;
use App\Models\ClientMonthSettlementEvent;
use App\Models\ClientReport;
use App\Models\ClientRetainer;

/** Any retainer evidence keeps the retainer layout, so a client signed for next month or an ex-retainer keeps its bank. */
final class ClientBillingMode
{
    public const string RETAINER = 'retainer';

    public const string ORDER = 'order';

    public function of(Client $client): string
    {
        return $this->forIds([$client->id])[$client->id];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public function forIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $evidence = ClientRetainer::query()->whereIn('client_id', $ids)->distinct()->pluck('client_id')
            ->merge(ClientMonthClosure::query()->whereIn('client_id', $ids)->distinct()->pluck('client_id'))
            ->merge(ClientMonthSettlementEvent::query()->whereIn('client_id', $ids)->distinct()->pluck('client_id'))
            ->merge(ClientReport::query()
                ->whereIn('client_id', $ids)
                ->where('period_type', ClientReport::PERIOD_MONTH)
                ->whereNotNull('contracted_hours')
                ->get(['client_id', 'period_type', 'period_start', 'period_end'])
                ->filter(fn (ClientReport $report): bool => $report->coversWholeMonth())
                ->pluck('client_id'))
            ->map(intval(...))
            ->flip();

        return collect($ids)->mapWithKeys(fn (int $id): array => [$id => $evidence->has($id) ? self::RETAINER : self::ORDER])->all();
    }
}
