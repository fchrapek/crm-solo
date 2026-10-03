<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Who may open Horizon outside the local environment: the owner of the
     * operator account (the first one), since job payloads span every account.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user): bool => $user !== null
            && (bool) $user->owner
            && (int) $user->account_id === (int) Account::query()->orderBy('id')->value('id'));
    }
}
