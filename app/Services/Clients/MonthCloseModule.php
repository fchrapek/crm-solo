<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Support\JsonSettings;
use Closure;

/** Enrolling a client into the close while Month close is off turns the module on: a client in the close needs it. */
final class MonthCloseModule
{
    /**
     * Runs a write that may enrol the client, then switches the module on when the client ended up enrolled.
     *
     * @return bool whether the module was turned on
     */
    public function enrol(Client $client, Closure $write): bool
    {
        $write();
        $enrolled = Client::query()->whereKey($client->id)->whereNotNull('month_close_type')->where('include_in_month_close', true)->exists();
        $account = $client->account()->firstOrFail();
        if (! $enrolled || $account->usesModule('month_close')) {
            return false;
        }
        JsonSettings::write('accounts', $account->id, ['month_close' => true]);

        return true;
    }
}
