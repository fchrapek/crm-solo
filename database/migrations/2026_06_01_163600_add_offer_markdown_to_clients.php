<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Stored verbatim — typically the markdown of the offer the
            // client agreed to (package tiers, baseline scope, overage rate).
            // Surfaced in the Reports tab as a reference for what's covered.
            $table->longText('offer_markdown')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('offer_markdown');
        });
    }
};
