<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_sessions', function (Blueprint $table) {
            // Parent tables (tasks, accounts, time_entries) all use int(10)
            // unsigned ids from the older `$table->increments()` pattern, so
            // every FK column here matches that type — `foreignId()` defaults
            // to bigint and would fail with "Foreign key constraint is
            // incorrectly formed" on MariaDB.
            $table->id();
            $table->unsignedInteger('task_id');
            $table->unsignedInteger('account_id');
            $table->string('cli');
            $table->string('base_branch');
            $table->string('branch_name');
            $table->string('worktree_path');
            $table->unsignedInteger('time_entry_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            // 'stopped' = user clicked End Session; 'crashed' = stop() called
            // but ttyd PID was already dead. Nullable while session is running.
            $table->string('ended_reason')->nullable();
            $table->timestamps();

            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('time_entry_id')->references('id')->on('time_entries')->nullOnDelete();

            $table->index(['task_id', 'started_at']);
            // Composite for the "find the currently-open session for this task"
            // query the launcher runs on stop(). At most one row per task has
            // ended_at IS NULL at a time, so this stays selective.
            $table->index(['task_id', 'ended_at']);
        });

        // Backfill: any task with a live session (session_pid not null) gets a
        // matching "running" task_sessions row so the new code path can close
        // it cleanly on the next stop(). Without this, an in-flight session at
        // migration time would never get a history row.
        //
        // started_at sources from the matching open TimeEntry row (the only
        // surviving "session started" timestamp). branch_name + worktree_path
        // are reconstructed from TerminalSessionLauncher's conventions:
        // branch = "session/task-{id}", worktree = <repo>/.worktrees/task-{id}.
        // base_branch is unknown post-hoc; 'unknown' lets the UI distinguish
        // backfilled rows from forward rows.
        $liveTasks = DB::table('tasks')
            ->whereNotNull('session_pid')
            ->whereNotNull('cli')
            ->get(['id', 'cli', 'project_id']);

        foreach ($liveTasks as $task) {
            $repoLocalPath = DB::table('repositories')
                ->where('project_id', $task->project_id)
                ->orderBy('id')
                ->value('local_path');

            $accountId = DB::table('projects')->where('id', $task->project_id)->value('account_id');
            if ($accountId === null) {
                continue;
            }

            $openTimeEntry = DB::table('time_entries')
                ->where('task_id', $task->id)
                ->where('source', 'terminal_session')
                ->whereNull('end_time')
                ->orderBy('start_time', 'desc')
                ->first();

            $startedAt = $openTimeEntry?->start_time ?? now()->toDateTimeString();

            DB::table('task_sessions')->insert([
                'task_id' => $task->id,
                'account_id' => $accountId,
                'cli' => $task->cli,
                'base_branch' => 'unknown',
                'branch_name' => 'session/task-'.$task->id,
                'worktree_path' => $repoLocalPath !== null
                    ? mb_rtrim($repoLocalPath, '/').'/.worktrees/task-'.$task->id
                    : '',
                'time_entry_id' => $openTimeEntry?->id,
                'started_at' => $startedAt,
                'ended_at' => null,
                'ended_reason' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_sessions');
    }
};
