<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Account members are managed by an owner; anyone may edit their own profile.
 * Cross-account rows never get this far (User::resolveRouteBinding 404s).
 */
final class UserPolicy
{
    public function create(User $actor): bool
    {
        return (bool) $actor->owner;
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->is($target) || (bool) $actor->owner;
    }

    public function delete(User $actor, User $target): bool
    {
        return (bool) $actor->owner;
    }

    public function restore(User $actor, User $target): bool
    {
        return (bool) $actor->owner;
    }
}
