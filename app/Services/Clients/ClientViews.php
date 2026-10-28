<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientView;
use App\Models\User;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/** A visit's start lives in the cache, not the session, so every request of the visit sees the same one. */
final class ClientViews
{
    public const int FALLBACK_DAYS = 7;

    public const int VISIT_GAP_MINUTES = 30;

    private const int LOCK_SECONDS = 5;

    /** Reads the previous view, then records this one; returns where "nowe" starts for this render. */
    public function open(User $user, Client $client): CarbonImmutable
    {
        $this->guard($user, $client);

        $lock = Cache::lock($this->key($user, $client).'.lock', self::LOCK_SECONDS);

        try {
            return $lock->block(self::LOCK_SECONDS, fn (): CarbonImmutable => $this->record($user, $client, $lock));
        } catch (LockTimeoutException) {
            return $this->since($user, $client);
        }
    }

    /** Where "nowe" starts without recording a view. */
    public function since(User $user, Client $client): CarbonImmutable
    {
        $this->guard($user, $client);

        return $this->sinceAt($this->previous($user, $client), $this->visit($user, $client), CarbonImmutable::now('UTC'));
    }

    private function record(User $user, Client $client, Lock $lock): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $since = $this->sinceAt($this->previous($user, $client), $this->visit($user, $client), $now);
        $row = fn () => ClientView::query()->where('user_id', $user->id)->where('client_id', $client->id);

        // Both statements only ever move viewed_at forward, so a request that lost its lock cannot undo a later one.
        ClientView::query()->insertOrIgnore([
            'user_id' => $user->id, 'client_id' => $client->id, 'viewed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $row()->where('viewed_at', '<', $now)->update(['viewed_at' => $now, 'updated_at' => $now]);

        $seen = $this->previous($user, $client);
        if ($seen !== null && $seen->equalTo($now) && $lock->isOwnedByCurrentProcess()) {
            Cache::put($this->key($user, $client), ['since' => $since->getTimestamp(), 'seen' => $now->getTimestamp()], now()->addMinutes(self::VISIT_GAP_MINUTES * 2));
        }

        return $since;
    }

    private function previous(User $user, Client $client): ?CarbonImmutable
    {
        $viewed = ClientView::query()
            ->where('user_id', $user->id)
            ->where('client_id', $client->id)
            ->value('viewed_at');

        return $viewed !== null ? CarbonImmutable::parse($viewed, 'UTC') : null;
    }

    /** @return array{since?: mixed, seen?: mixed}|null */
    private function visit(User $user, Client $client): ?array
    {
        $visit = Cache::get($this->key($user, $client));

        return is_array($visit) ? $visit : null;
    }

    /** @param  array{since?: mixed, seen?: mixed}|null  $visit */
    private function sinceAt(?CarbonImmutable $previous, ?array $visit, CarbonImmutable $now): CarbonImmutable
    {
        if ($previous === null) {
            return LocalCalendar::today()->subDays(self::FALLBACK_DAYS)->setTimezone('UTC');
        }

        $continues = $visit !== null
            && ($visit['seen'] ?? null) === $previous->getTimestamp()
            && is_int($visit['since'] ?? null)
            && $previous->greaterThan($now->subMinutes(self::VISIT_GAP_MINUTES));

        return $continues ? CarbonImmutable::createFromTimestamp($visit['since'], 'UTC') : $previous;
    }

    private function guard(User $user, Client $client): void
    {
        abort_unless($user->account_id === $client->account_id, 404);
    }

    private function key(User $user, Client $client): string
    {
        return "client_views.{$user->id}.{$client->id}";
    }
}
