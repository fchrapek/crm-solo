<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives every task already completed a finish date. updated_at is what
 * reports read as the completion date until now, so using it regenerates
 * existing reports to the same task set.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')
            ->where('is_completed', true)
            ->whereNull('finished_at')
            ->update(['finished_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        // Backfilled and real finish dates are indistinguishable; dropping the column reverses both.
    }
};
