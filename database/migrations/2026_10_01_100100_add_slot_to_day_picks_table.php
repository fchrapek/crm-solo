<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One of the day's five places, unique per account and date, so the database
 * itself refuses a sixth pick when two requests add one at the same moment.
 * Existing picks take places 1 to 5 in their current order; any beyond the
 * fifth keep a null place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('day_picks', function (Blueprint $table): void {
            $table->unsignedTinyInteger('slot')->nullable()->after('position');
        });

        DB::table('day_picks')
            ->orderBy('account_id')->orderBy('date')->orderBy('position')->orderBy('id')
            ->get(['id', 'account_id', 'date'])
            ->groupBy(fn (object $row): string => $row->account_id.'|'.mb_substr((string) $row->date, 0, 10))
            ->each(function ($rows): void {
                foreach ($rows->take(5)->values() as $index => $row) {
                    DB::table('day_picks')->where('id', $row->id)->update(['slot' => $index + 1]);
                }
            });

        Schema::table('day_picks', function (Blueprint $table): void {
            $table->unique(['account_id', 'date', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::table('day_picks', function (Blueprint $table): void {
            $table->dropUnique(['account_id', 'date', 'slot']);
            $table->dropColumn('slot');
        });
    }
};
