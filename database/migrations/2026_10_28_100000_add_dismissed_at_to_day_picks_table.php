<?php

declare(strict_types=1);

use App\Support\LocalCalendar;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An unfinished pick from an earlier day waits on today until the owner
 * dismisses it; the pick itself stays, so the past day's plan is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('day_picks', function (Blueprint $table): void {
            $table->timestamp('dismissed_at')->nullable()->after('slot');
        });

        // Carrying starts on the day this ships; older unfinished picks would otherwise all land on today at once.
        DB::table('day_picks')
            ->where('date', '<', LocalCalendar::today()->toDateString())
            ->update(['dismissed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('day_picks', function (Blueprint $table): void {
            $table->dropColumn('dismissed_at');
        });
    }
};
