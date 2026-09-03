<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the monthly deliverable is for this client:
        //   report        — full client report (default).
        //   summary_email — a short summary email instead of a full report.
        //   none          — no written deliverable.
        // Drives which deliverable step the month-close checklist seeds.
        Schema::table('clients', function (Blueprint $table) {
            $table->string('report_mode', 20)->default('report')->after('month_close_type');
        });

        // Snapshotted onto the run so retagging the client later doesn't
        // rewrite a past close's checklist.
        Schema::table('month_close_runs', function (Blueprint $table) {
            $table->string('report_mode', 20)->default('report')->after('close_type');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('report_mode');
        });

        Schema::table('month_close_runs', function (Blueprint $table) {
            $table->dropColumn('report_mode');
        });
    }
};
