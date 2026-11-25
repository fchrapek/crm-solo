<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

/** SQLite has no row locks: the plan row's locking read fails here as MariaDB fails one that waited past its bound. */
trait TimesOutThePlanLock
{
    private function planLockTimesOut(): void
    {
        DB::connection()->beforeExecuting(function (string $query, array $bindings, $connection): void {
            // Level 1 is RefreshDatabase's own transaction; the turn is taken inside the planner's.
            if (str_contains($query, 'from "day_plan_locks"') && $connection->transactionLevel() > 1) {
                throw new QueryException($connection->getName(), $query, $bindings, new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'));
            }
        });
    }
}
