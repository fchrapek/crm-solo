<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Direct hourly rate for clients on cooperation_type=hourly. Stored netto.
        // Retainer-based clients keep using ClientRetainer rows (which preserve
        // history); pure-hourly clients use this single field with no audit
        // trail — by explicit product decision (history-of-rate-changes is
        // overkill for ad-hoc billing in a solo CRM).
        Schema::table('clients', function (Blueprint $table) {
            $table->decimal('hourly_rate', 10, 2)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('hourly_rate');
        });
    }
};
