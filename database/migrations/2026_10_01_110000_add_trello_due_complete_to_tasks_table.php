<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The card's own "due date complete" flag, kept so the CRM can recompute a
 * card's completion (Done list or due complete) when the list mapping changes
 * without waiting for the next sync. Filled by the sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->boolean('trello_due_complete')->default(false)->after('trello_list_id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('trello_due_complete');
        });
    }
};
