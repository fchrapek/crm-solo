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
            $table->enum('source', ['email', 'trello', 'manual'])->default('trello')->after('is_completed');
            $table->unsignedInteger('source_email_id')->nullable()->after('source');
            $table->boolean('is_reviewed')->default(true)->after('source_email_id');
            $table->text('review_note')->nullable()->after('is_reviewed');
            $table->timestamp('rejected_at')->nullable()->after('review_note');

            $table->foreign('source_email_id')->references('id')->on('emails')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['source_email_id']);
            $table->dropColumn(['source', 'source_email_id', 'is_reviewed', 'review_note', 'rejected_at']);
        });
    }
};
