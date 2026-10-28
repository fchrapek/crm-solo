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
     * Only the operator account's owner may open Horizon, never on the demo, where that owner is the shared visitor login.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user): bool => $user !== null
            && ! config('app.demo')
            && (bool) $user->owner
            && (int) $user->account_id === Account::operatorId());
    }
}
