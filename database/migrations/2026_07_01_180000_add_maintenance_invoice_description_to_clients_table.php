<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Line-item wording for this client's recurring maintenance draft invoice
        // (e.g. "Utrzymanie strony i wsparcie techniczne"). Editable per client;
        // falls back to InfaktService::DEFAULT_MAINTENANCE_DESCRIPTION when null.
        Schema::table('clients', function (Blueprint $table) {
            $table->string('maintenance_invoice_description', 500)->nullable()->after('ssh_config');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('maintenance_invoice_description');
        });
    }
};
