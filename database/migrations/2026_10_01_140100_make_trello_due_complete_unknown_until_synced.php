<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * trello_due_complete was added as false on every row, which reads as "the
 * card's due date is not complete" for cards no sync has looked at yet. Null
 * now means "not yet synced"; the next sync writes the real value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->boolean('trello_due_complete')->nullable()->default(null)->change();
        });

        DB::table('tasks')->update(['trello_due_complete' => null]);
    }

    public function down(): void
    {
        DB::table('tasks')->whereNull('trello_due_complete')->update(['trello_due_complete' => false]);

        Schema::table('tasks', function (Blueprint $table): void {
            $table->boolean('trello_due_complete')->default(false)->change();
        });
    }
};
