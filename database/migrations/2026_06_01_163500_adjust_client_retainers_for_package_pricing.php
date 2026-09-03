<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Retainers are package-priced, not h × rate. The package buys a fixed
        // monthly fee and an included hour count; work beyond the included
        // hours is billed at a separate overage rate. `hourly_rate` was a
        // single-rate field that doesn't model the cheap-hours-in-package /
        // expensive-hours-overage split — replace it.
        Schema::table('client_retainers', function (Blueprint $table) {
            $table->decimal('monthly_fee', 10, 2)->nullable()->after('monthly_hours');
            $table->decimal('overage_hourly_rate', 10, 2)->nullable()->after('monthly_fee');
        });

        Schema::table('client_retainers', function (Blueprint $table) {
            $table->dropColumn('hourly_rate');
        });
    }

    public function down(): void
    {
        Schema::table('client_retainers', function (Blueprint $table) {
            $table->decimal('hourly_rate', 10, 2)->nullable()->after('monthly_hours');
        });

        Schema::table('client_retainers', function (Blueprint $table) {
            $table->dropColumn(['monthly_fee', 'overage_hourly_rate']);
        });
    }
};
