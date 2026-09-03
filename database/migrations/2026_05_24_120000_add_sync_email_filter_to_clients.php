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
            // null  = sync all candidate addresses (client.email + every
            //         contact.emails entry) — legacy behaviour, what every
            //         client gets by default.
            // []    = sync NOTHING (effectively disable per-client sync).
            // [...] = sync only these addresses. The set is intersected
            //         against the candidate list at query time, so removing
            //         a contact also removes its addresses from the effective
            //         filter without anyone editing the JSON.
            $table->json('sync_email_filter')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('sync_email_filter');
        });
    }
};
