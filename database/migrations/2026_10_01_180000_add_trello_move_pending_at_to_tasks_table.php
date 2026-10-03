<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Set while a finished card has moved to an active list and the sync has not
 * yet learnt when that move happened (the lookup failed or was deferred).
 * Every sync asks again until Trello answers; the answer clears it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->timestamp('trello_move_pending_at')->nullable()->after('trello_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('trello_move_pending_at');
        });
    }
};
