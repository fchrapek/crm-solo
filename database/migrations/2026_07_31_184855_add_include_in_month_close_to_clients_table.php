<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Month-close inclusion becomes an explicit flag instead of "type is set":
 * unchecking excludes a client from /month-close while preserving its
 * maintenance/gig type for later. Backfill: everyone currently typed is in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('include_in_month_close')->default(false)->after('month_close_type');
        });

        DB::table('clients')
            ->whereNotNull('month_close_type')
            ->update(['include_in_month_close' => true]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('include_in_month_close');
        });
    }
};
