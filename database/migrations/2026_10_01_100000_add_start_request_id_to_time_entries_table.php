<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client's id for one "start timer" click. Unique per account, so a
 * repeated or raced request returns the timer the first one opened instead of
 * opening a second. Null on every entry not started from the stopwatch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table): void {
            $table->string('start_request_id', 64)->nullable()->after('source');
            $table->unique(['account_id', 'start_request_id']);
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table): void {
            $table->dropUnique(['account_id', 'start_request_id']);
            $table->dropColumn('start_request_id');
        });
    }
};
