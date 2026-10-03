<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The card's dateLastActivity as of the last sync that wrote it: the version
 * a later sync compares against, so an older fetch never overwrites a newer
 * one. Null until the first sync after this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->timestamp('trello_activity_at')->nullable()->after('trello_list_id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('trello_activity_at');
        });
    }
};
