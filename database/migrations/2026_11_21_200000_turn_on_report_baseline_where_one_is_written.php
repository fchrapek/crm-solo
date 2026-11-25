<?php

declare(strict_types=1);

use App\Support\JsonSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Zakres bazowy raportu is an account setting, off by default: an account that already wrote one keeps seeing it. */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('clients')->whereNotNull('report_baseline_markdown')->where('report_baseline_markdown', '<>', '')->distinct()->pluck('account_id');

        // Key by key, so a settings write landing during the deploy keeps its own keys.
        foreach (DB::table('accounts')->whereIn('id', $ids)->pluck('id') as $id) {
            JsonSettings::write('accounts', (int) $id, ['report_baseline' => true]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('accounts')->whereNotNull('settings')->pluck('id') as $id) {
            JsonSettings::forget('accounts', (int) $id, 'report_baseline');
        }
    }
};
