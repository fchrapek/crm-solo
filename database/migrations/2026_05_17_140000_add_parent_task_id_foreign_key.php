<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add FK `tasks.parent_task_id -> tasks.id` with ON DELETE SET NULL.
     *
     * Pre-existing rows that point at deleted parents (orphans) are nulled
     * first so the FK creation does not fail. The cascade is also enforced
     * at the model layer via Task::booted's deleting hook for portability
     * across drivers and mass-delete edge cases — this migration adds the
     * database-level safety net on MariaDB.
     */
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // SQLite (tests) — schema enforcement happens via the model event.
            return;
        }

        // Null any parent_task_id that points at a now-deleted task so the FK creation succeeds.
        DB::statement(
            'UPDATE tasks t LEFT JOIN tasks p ON t.parent_task_id = p.id '.
            'SET t.parent_task_id = NULL '.
            'WHERE t.parent_task_id IS NOT NULL AND p.id IS NULL'
        );

        // Match parent_task_id width + signedness to tasks.id, which the original
        // create_tasks_table migration declared via $table->increments('id') —
        // that resolves to INT UNSIGNED AUTO_INCREMENT on MariaDB, NOT bigint.
        // MariaDB requires the FK column type to match the referenced column
        // exactly; using BIGINT here fails with errno 150 at deploy time.
        DB::statement('ALTER TABLE tasks MODIFY parent_task_id INT UNSIGNED NULL');

        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreign('parent_task_id', 'tasks_parent_task_id_foreign')
                ->references('id')->on('tasks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropForeign('tasks_parent_task_id_foreign');
        });

        DB::statement('ALTER TABLE tasks MODIFY parent_task_id INT NULL');
    }
};
