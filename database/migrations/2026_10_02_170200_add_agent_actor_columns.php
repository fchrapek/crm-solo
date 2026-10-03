<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who wrote a record through an agent transport, kept on the record itself:
 * time entries and journal events when created, a task when it is ticked.
 * Null on everything written from the web UI or before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->unsignedInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('actor_token_id')->nullable();
            $table->string('actor_via', 16)->nullable();
            $table->string('actor_session_id', 128)->nullable()->index();

            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('actor_token_id')->references('id')->on('personal_access_tokens')->nullOnDelete();
        });

        Schema::table('client_lifecycle_events', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_token_id')->nullable();
            $table->string('actor_via', 16)->nullable();
            $table->string('actor_session_id', 128)->nullable()->index();

            $table->foreign('actor_token_id')->references('id')->on('personal_access_tokens')->nullOnDelete();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedInteger('finished_by_user_id')->nullable();
            $table->unsignedBigInteger('finished_by_token_id')->nullable();
            $table->string('finished_via', 16)->nullable();
            $table->string('finished_session_id', 128)->nullable()->index();

            $table->foreign('finished_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('finished_by_token_id')->references('id')->on('personal_access_tokens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['finished_by_user_id']);
            $table->dropForeign(['finished_by_token_id']);
            $table->dropIndex(['finished_session_id']);
            $table->dropColumn(['finished_by_user_id', 'finished_by_token_id', 'finished_via', 'finished_session_id']);
        });

        Schema::table('client_lifecycle_events', function (Blueprint $table) {
            $table->dropForeign(['actor_token_id']);
            $table->dropIndex(['actor_session_id']);
            $table->dropColumn(['actor_token_id', 'actor_via', 'actor_session_id']);
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropForeign(['actor_user_id']);
            $table->dropForeign(['actor_token_id']);
            $table->dropIndex(['actor_session_id']);
            $table->dropColumn(['actor_user_id', 'actor_token_id', 'actor_via', 'actor_session_id']);
        });
    }
};
