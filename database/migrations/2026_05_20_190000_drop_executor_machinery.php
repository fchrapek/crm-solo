<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops everything the deleted executor pipeline owned:
 * - task_runs + task_run_artifacts (run tracking)
 * - task_design_references (Figma frame refs per viewport)
 * - the agent_* / brief_* / executor_* / review_* columns on `tasks`
 * - role on task_attachments (block_asset was an executor concept)
 *
 * One-shot drop. The previous flow has no path back, so down() recreates
 * minimal column stubs to let local rollback succeed without restoring
 * actual data (which is gone with the migration). For production this is
 * a forward-only delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('task_run_artifacts');
        Schema::dropIfExists('task_runs');
        Schema::dropIfExists('task_design_references');

        // Drop the composite index that referenced is_agent_ready before
        // dropping the column. SQLite (used in tests) rebuilds indexes on
        // ALTER and fails when the column they reference is gone. The index
        // may be absent on legacy environments — swallow the "doesn't exist"
        // case so the migration is idempotent across DB instances.
        try {
            Schema::table('tasks', function (Blueprint $table) {
                $table->dropIndex('tasks_agent_board_idx');
            });
        } catch (Throwable) {
            // Index isn't there — nothing to drop.
        }

        // Drop FK first — MariaDB refuses to drop a column referenced by an FK,
        // even if the migration is going to drop the column right after.
        // FK may already be gone from a partially-applied previous run; ignore.
        try {
            Schema::table('tasks', function (Blueprint $table) {
                $table->dropForeign(['agent_repository_id']);
            });
        } catch (Throwable) {
            // FK already dropped.
        }

        Schema::table('tasks', function (Blueprint $table) {
            $columns = [
                'executor_type', 'executor_config',
                'planning_packet', 'implementation_packet',
                'is_agent_ready', 'agent_prompt', 'agent_repository_id',
                'agent_base_branch', 'agent_max_iterations',
                'success_criteria', 'design_tokens',
                'compiled_brief', 'brief_status', 'brief_compiled_at', 'brief_error',
                'force_browser_verification', 'return_result_url',
                'auto_review', 'group_max_budget_usd', 'max_budget_usd', 'run_audits',
                'review_config',
                'target_region_selector', 'target_region_text',
                'extracted_content',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('tasks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('task_attachments', function (Blueprint $table) {
            if (Schema::hasColumn('task_attachments', 'role')) {
                $table->dropColumn('role');
            }
        });
    }

    public function down(): void
    {
        // Forward-only delete — down() just re-adds blank columns so a
        // local rollback doesn't error. Tables are not recreated.
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('executor_type', 64)->nullable();
            $table->json('executor_config')->nullable();
            $table->json('planning_packet')->nullable();
            $table->json('implementation_packet')->nullable();
            $table->boolean('is_agent_ready')->default(false);
            $table->text('agent_prompt')->nullable();
            $table->unsignedInteger('agent_repository_id')->nullable();
            $table->string('agent_base_branch', 255)->nullable();
            $table->unsignedSmallInteger('agent_max_iterations')->default(1);
            $table->text('success_criteria')->nullable();
            $table->json('design_tokens')->nullable();
            $table->json('compiled_brief')->nullable();
            $table->string('brief_status', 16)->nullable();
            $table->timestamp('brief_compiled_at')->nullable();
            $table->text('brief_error')->nullable();
            $table->boolean('force_browser_verification')->default(false);
            $table->boolean('return_result_url')->default(false);
            $table->boolean('auto_review')->default(true);
            $table->float('group_max_budget_usd')->nullable();
            $table->float('max_budget_usd')->nullable();
            $table->boolean('run_audits')->default(false);
            $table->json('review_config')->nullable();
            $table->string('target_region_selector', 500)->nullable();
            $table->string('target_region_text', 200)->nullable();
            $table->json('extracted_content')->nullable();
        });

        Schema::table('task_attachments', function (Blueprint $table) {
            $table->string('role', 32)->default('context')->after('label');
        });
    }
};
