<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_previews', function (Blueprint $table) {
            // Parent tables (tasks, projects, accounts) all use int(10)
            // unsigned ids — every FK must match. See _MIGRATIONS_EXPLAINED.md.
            $table->id();
            $table->unsignedInteger('task_id');
            $table->unsignedInteger('project_id');
            $table->unsignedInteger('account_id');
            // ttyd process PID + bound port. We poll PID for liveness; port
            // drives the embedded terminal iframe URL.
            $table->unsignedInteger('pid')->nullable();
            $table->unsignedInteger('port')->nullable();
            // Snapshot of what ran — projects.preview_command might change
            // between previews, so this row captures the actual command used.
            $table->string('command', 500);
            $table->string('working_dir', 500);
            // Optional URL the user clicks to open the running preview.
            $table->string('url', 500)->nullable();
            // ttyd stdout log path (storage/logs/preview-task-{id}-*.log).
            $table->string('log_path', 500)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('stopped_at')->nullable();
            // 'stopped' = user clicked Stop Preview; 'crashed' = stop() called
            // but ttyd PID was already dead. Nullable while running.
            $table->string('stopped_reason')->nullable();
            $table->timestamps();

            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();

            $table->index(['task_id', 'started_at']);
            // "Find the currently-running preview for this task" — at most one
            // row per task has stopped_at IS NULL at a time, stays selective.
            $table->index(['task_id', 'stopped_at']);
            // "Find any running preview for this project" — drives the
            // per-project mutex check on Start.
            $table->index(['project_id', 'stopped_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_previews');
    }
};
