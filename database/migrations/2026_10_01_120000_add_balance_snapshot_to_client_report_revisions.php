<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A revision keeps the balance figures it replaced, since a balance edit or a regenerate changes them.
        Schema::table('client_report_revisions', function (Blueprint $table) {
            $table->decimal('opening_balance_hours_before', 8, 2)->nullable()->after('actual_hours_before');
            $table->decimal('rollover_cap_hours_before', 8, 2)->nullable()->after('opening_balance_hours_before');
        });
    }

    public function down(): void
    {
        Schema::table('client_report_revisions', function (Blueprint $table) {
            $table->dropColumn(['opening_balance_hours_before', 'rollover_cap_hours_before']);
        });
    }
};
