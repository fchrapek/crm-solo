<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_reports', function (Blueprint $table) {
            $table->timestamp('opening_set_at')->nullable();
        });

        // Revisions cannot tell a recalculating regenerate from a plain one, so every opening once set by hand stays the owner's.
        DB::table('client_report_revisions')
            ->where('reason', 'opening_balance')
            ->groupBy('client_report_id')
            ->selectRaw('client_report_id, MAX(created_at) AS set_at')
            ->get()
            ->each(fn (object $row) => DB::table('client_reports')->where('id', $row->client_report_id)->update(['opening_set_at' => $row->set_at]));
    }

    public function down(): void
    {
        Schema::table('client_reports', function (Blueprint $table) {
            $table->dropColumn('opening_set_at');
        });
    }
};
