<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each attempt sent, as a fingerprint of its currency and line items,
 * so a send that lost its answer is recognised in Infakt only by an exact
 * match. One Infakt invoice can be recorded for at most one group of a
 * client's month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('infakt_draft_requests', function (Blueprint $table): void {
            $table->char('payload_fingerprint', 64)->nullable()->after('lease_expires_at');
            $table->unique(['client_id', 'period', 'invoice_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('infakt_draft_requests', function (Blueprint $table): void {
            $table->dropUnique(['client_id', 'period', 'invoice_uuid']);
            $table->dropColumn('payload_fingerprint');
        });
    }
};
