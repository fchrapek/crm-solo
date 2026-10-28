<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientReport;
use Closure;
use Illuminate\Support\Facades\DB;

/** Lock order for a client's money: the client row first, its report rows after, so two writers never wait on each other in opposite order. */
final class ClientFinancialLock
{
    /**
     * Runs $then inside one transaction holding the client row, archived clients included.
     *
     * @template T
     *
     * @param  Closure(Client): T  $then
     * @return T
     */
    public function client(int $clientId, Closure $then): mixed
    {
        return DB::transaction(function () use ($clientId, $then): mixed {
            $client = Client::query()->withTrashed()->lockForUpdate()->findOrFail($clientId);

            return $then($client);
        });
    }

    /**
     * Runs $then inside one transaction holding every client row named, taken in id order so two writers over the
     * same clients never wait on each other.
     *
     * @template T
     *
     * @param  list<int>  $clientIds
     * @param  Closure(): T  $then
     * @return T
     */
    public function clients(array $clientIds, Closure $then): mixed
    {
        $ids = array_values(array_unique($clientIds));
        sort($ids);

        return DB::transaction(function () use ($ids, $then): mixed {
            foreach ($ids as $id) {
                Client::query()->withTrashed()->lockForUpdate()->findOrFail($id);
            }

            return $then();
        });
    }

    /**
     * Runs $then on the report as stored now, re-read under the client lock.
     *
     * @template T
     *
     * @param  Closure(ClientReport, Client): T  $then
     * @return T
     */
    public function report(ClientReport $report, Closure $then): mixed
    {
        return $this->client($report->client_id, function (Client $client) use ($report, $then): mixed {
            $fresh = ClientReport::query()->lockForUpdate()->findOrFail($report->id);

            return $then($fresh, $client);
        });
    }
}
