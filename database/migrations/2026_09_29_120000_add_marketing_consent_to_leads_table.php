<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The visitor's marketing consent at form submit, as recorded by the
        // site (kiwwwi lead API `consent`). 'granted' / 'denied'; NULL means
        // unknown (manual capture, or an entry submitted before the site
        // started recording it). Only 'granted' lets Google click ids stay in
        // the lead's notes; see App\Services\Leads\MarketingConsent.
        Schema::table('leads', function (Blueprint $table) {
            $table->string('marketing_consent', 16)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('marketing_consent');
        });
    }
};
