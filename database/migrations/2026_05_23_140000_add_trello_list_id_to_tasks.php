<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Trello list this card lives in. Stored on the task so that
            // `updateTrelloListMapping` can re-derive `list_name` for every
            // task without a fresh API roundtrip; sync writes it on every
            // upsert. Nullable for non-Trello tasks.
            $table->string('trello_list_id', 64)->nullable()->after('trello_card_id');
            $table->index(['project_id', 'trello_list_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'trello_list_id']);
            $table->dropColumn('trello_list_id');
        });
    }
};
