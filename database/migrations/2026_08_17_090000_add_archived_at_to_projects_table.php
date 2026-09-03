<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to put a Trello board you never want to see again.
 *
 * `syncAllBoards` imports every board the token can reach, so a personal board
 * or an old client's leftovers land in the CRM as a client-less project. There
 * was no way to say "not this one": deleting it lost the tasks and the next
 * sync recreated the project anyway.
 *
 * Same semantics as `tasks.archived_at` — hidden from default views, row kept,
 * restorable. Sync reads it as a tombstone and skips the board entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('include_in_month_close')->index();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
