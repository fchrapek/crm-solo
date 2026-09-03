<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tasks.review_config — JSON bag for the per-task QA pipeline settings:
 *
 *   {
 *     "preset": "quick" | "visual" | "audited" | "comprehensive" | "custom",
 *     "models": ["claude-opus-4-6", ...],         // models to fan out to
 *     "browser_tools": ["vercel", "chrome_devtools"],  // which browser MCP servers the agent gets
 *     "audits": {
 *       "screenshot":      bool,                  // before/after screenshots
 *       "lighthouse_perf": bool,                  // (Q4 follow-up — UI exposed, backend stubbed)
 *       "a11y":            bool,                  // (Q4 follow-up)
 *       "seo":             bool                   // (Q4 follow-up)
 *     }
 *   }
 *
 * Null = use Task::DEFAULT_REVIEW_CONFIG. Forward-only; existing rows stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->json('review_config')->nullable()->after('run_audits');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('review_config');
        });
    }
};
