<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // External-system identity for pulled leads — e.g. "ff-127" for
        // FluentForm submission 127 pulled by kiwwwi:sync-leads. The unique
        // (account_id, external_ref) pair is the dedupe backbone: re-running a
        // pull can only skip, never duplicate, and because the CRM-side lookup
        // includes soft-deleted rows, a lead deleted in the CRM stays deleted
        // instead of resurrecting on the next sync. Nullable: manually
        // captured leads have no external identity.
        Schema::table('leads', function (Blueprint $table) {
            $table->string('external_ref', 64)->nullable()->after('client_id');
            $table->unique(['account_id', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique(['account_id', 'external_ref']);
            $table->dropColumn('external_ref');
        });
    }
};
