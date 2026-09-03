<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_reports', function (Blueprint $table) {
            // Hours-bank opening balance carried into this period. Closing is
            // derived (opening + contracted − actual), so only opening is stored.
            $table->decimal('opening_balance_hours', 8, 2)->nullable()->after('actual_hours');
        });
    }

    public function down(): void
    {
        Schema::table('client_reports', function (Blueprint $table) {
            $table->dropColumn('opening_balance_hours');
        });
    }
};
