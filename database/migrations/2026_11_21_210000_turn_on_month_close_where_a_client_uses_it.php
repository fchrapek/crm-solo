<?php

declare(strict_types=1);

use App\Support\JsonSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Zamknięcie miesiąca is an account module, off by default: an account with a client in the close, or any close run, keeps it. */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('clients')
            ->where(fn ($query) => $query->whereNotNull('month_close_type')->orWhere('include_in_month_close', true))
            ->distinct()
            ->pluck('account_id')
            ->merge(DB::table('month_close_runs')->distinct()->pluck('account_id'))
            ->unique();

        // Key by key, so a settings write landing during the deploy keeps its own keys.
        foreach (DB::table('accounts')->whereIn('id', $ids)->pluck('id') as $id) {
            JsonSettings::write('accounts', (int) $id, ['month_close' => true]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('accounts')->whereNotNull('settings')->pluck('id') as $id) {
            JsonSettings::forget('accounts', (int) $id, 'month_close');
        }
    }
};
