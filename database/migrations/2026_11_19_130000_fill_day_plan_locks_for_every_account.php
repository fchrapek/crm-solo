<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Every account gets its plan row up front, so no plan change inserts one; an account created later gets it from Account::created. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('day_plan_locks')->insertUsing(
            ['account_id'],
            DB::table('accounts')->select('id')->whereNotIn('id', DB::table('day_plan_locks')->select('account_id')),
        );
    }

    public function down(): void
    {
        // The rows are the table's whole content; the table's own migration drops them.
    }
};
