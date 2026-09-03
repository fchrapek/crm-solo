<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quality-of-Delegated-Task additions (post-2026-05-09):
 *
 *   task_runs:
 *     - run_group_id (uuid, nullable)              — links N parallel runs of one task
 *     - run_purpose ('primary'|'parallel'|'review', default 'primary')
 *     - reviewed_by_run_id (FK self, nullable)    — if a reviewer agent ran on this primary
 *     - review_verdict (json, nullable)           — structured review output
 *     - user_verdict ('up'|'down', nullable)      — Q6 thumbs feedback
 *
 *   tasks:
 *     - auto_review (bool, default true)          — auto-dispatch reviewer after primary
 *     - group_max_budget_usd (float, nullable)    — per-fan-out cost ceiling
 *     - run_audits (bool, default false)          — Q4, off until lighthouse/axe wired
 *
 *   task_run_artifacts (NEW):
 *     - polymorphic-ish bag for screenshots, lighthouse JSON, axe JSON, diff summaries
 *
 * All additions are forward-only, default values keep existing rows valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->uuid('run_group_id')->nullable()->after('task_id');
            $table->string('run_purpose', 32)->default('primary')->after('run_group_id');
            // Soft FK only — self-referencing FK on a table being altered hits
            // "Foreign key constraint is incorrectly formed" on MariaDB. App code
            // resolves the relation via TaskRun::reviewer() instead.
            $table->unsignedBigInteger('reviewed_by_run_id')->nullable()->after('run_purpose');
            $table->json('review_verdict')->nullable()->after('reviewed_by_run_id');
            $table->string('user_verdict', 8)->nullable()->after('review_verdict');

            $table->index('run_group_id');
            $table->index('run_purpose');
            $table->index('reviewed_by_run_id');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('auto_review')->default(true)->after('return_result_url');
            $table->float('group_max_budget_usd')->nullable()->after('auto_review');
            $table->boolean('run_audits')->default(false)->after('group_max_budget_usd');
        });

        Schema::create('task_run_artifacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('run_id');
            $table->foreign('run_id')->references('id')->on('task_runs')->cascadeOnDelete();
            // type: screenshot_before | screenshot_after | lighthouse | axe | seo | diff_summary
            $table->string('type', 32);
            // Inline JSON for cheap structured payloads (lighthouse summary, axe violations,
            // SEO findings). Big binaries (screenshots) live on disk under file_path.
            $table->json('payload')->nullable();
            $table->string('file_path', 500)->nullable();
            $table->timestamps();

            $table->index(['run_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_run_artifacts');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['auto_review', 'group_max_budget_usd', 'run_audits']);
        });

        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropIndex(['run_group_id']);
            $table->dropIndex(['run_purpose']);
            $table->dropIndex(['reviewed_by_run_id']);
            $table->dropColumn([
                'run_group_id',
                'run_purpose',
                'reviewed_by_run_id',
                'review_verdict',
                'user_verdict',
            ]);
        });
    }
};
