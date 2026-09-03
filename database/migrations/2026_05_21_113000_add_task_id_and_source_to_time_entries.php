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
        Schema::table('time_entries', function (Blueprint $table) {
            // Optional link to the task whose terminal session produced this
            // entry. Null for Clockify-synced rows (Clockify has no task model)
            // and for manual entries that aren't tied to a specific task.
            $table->integer('task_id')->nullable()->index()->after('client_id');
            // Where this row came from. 'clockify' = pulled by ClockifyService,
            // 'terminal_session' = opened/closed by TerminalSessionLauncher,
            // 'manual' = user-created in the CRM. Drives merge rules so
            // Clockify pulls don't overwrite local entries by accident.
            $table->string('source', 32)->default('clockify')->after('task_id');
        });

        // Backfill: every existing row was created by ClockifyService.
        DB::table('time_entries')->update(['source' => 'clockify']);
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropColumn(['task_id', 'source']);
        });
    }
};
