<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A file pulled from the task's Trello card carries the card attachment's
 * id, unique per task, so fetching the card again never stores it twice.
 * Null for files uploaded in the CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_attachments', function (Blueprint $table): void {
            $table->string('trello_attachment_id', 64)->nullable()->after('label');
            $table->unique(['task_id', 'trello_attachment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('task_attachments', function (Blueprint $table): void {
            $table->dropUnique(['task_id', 'trello_attachment_id']);
            $table->dropColumn('trello_attachment_id');
        });
    }
};
