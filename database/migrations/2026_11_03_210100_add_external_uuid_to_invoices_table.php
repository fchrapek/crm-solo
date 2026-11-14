<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Infakt names an invoice by uuid in its draft tasks; the mirror keeps it beside the numeric id so the two join, and
 * keeps the kind and the parent (an advance a final invoice settles) that decide whether an invoice can be copied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('external_uuid', 64)->nullable()->after('external_id');
            $table->string('kind', 32)->nullable()->after('status');
            $table->string('parent_external_id')->nullable()->after('kind');
            $table->index(['account_id', 'external_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex(['account_id', 'external_uuid']);
            $table->dropColumn(['external_uuid', 'kind', 'parent_external_id']);
        });
    }
};
