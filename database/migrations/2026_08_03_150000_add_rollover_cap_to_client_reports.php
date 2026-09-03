<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The ceiling on what a month can offer: available = min(opening +
        // contracted, cap). Snapshotted alongside contracted_hours so a later
        // change to the retainer never rewrites what a past report promised.
        Schema::table('client_reports', function (Blueprint $table) {
            $table->decimal('rollover_cap_hours', 8, 2)->nullable()->after('opening_balance_hours');
        });
    }

    public function down(): void
    {
        Schema::table('client_reports', function (Blueprint $table) {
            $table->dropColumn('rollover_cap_hours');
        });
    }
};
