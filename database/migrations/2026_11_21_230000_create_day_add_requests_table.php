<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One "add a task to the day" submit: the client's id for it, unique per account, the payload it carried and the
 * task it made. The task id has no foreign key on purpose, so the record outlives a deleted task and a replay of
 * the same id can say so instead of making the task again.
 */
return new class extends Migration
{
    public function up(): void
    {
        // An earlier build kept the request id on tasks; a database that ran it loses that column here.
        if (Schema::hasIndex('tasks', ['project_id', 'create_request_id'], 'unique')) {
            Schema::table('tasks', fn (Blueprint $table) => $table->dropUnique(['project_id', 'create_request_id']));
        }
        if (Schema::hasColumn('tasks', 'create_request_id')) {
            Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('create_request_id'));
        }

        Schema::create('day_add_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('account_id');
            $table->string('request_id', 64);
            $table->string('fingerprint', 64);
            $table->unsignedInteger('task_id');
            $table->timestamps();

            $table->unique(['account_id', 'request_id']);
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_add_requests');
    }
};
