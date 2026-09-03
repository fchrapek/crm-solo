<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Offers moved to per-client PDF documents (category `oferta`) on
     * 2026-07-31; the PDFs are verified, so the source column goes.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('offer_markdown');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->longText('offer_markdown')->nullable()->after('notes');
        });
    }
};
