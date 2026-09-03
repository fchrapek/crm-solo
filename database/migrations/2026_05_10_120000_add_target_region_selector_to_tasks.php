<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `target_region_selector` — CSS selector for the page region under change.
 * When set, BEFORE/AFTER screenshots clip to that region's bounding box
 * via Puppeteer `page.screenshot({clip})` instead of stitching the whole page.
 *
 * Pairs with `task.review_config.viewport_widths` (JSON, default [1440, 390]
 * for visual_verify/full tier briefs) — the screenshot pipeline captures
 * one shot per viewport × the same region, so a desktop-only fix that
 * misses mobile triggers per-viewport pixel-diff failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('target_region_selector', 500)->nullable()->after('issue_url');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('target_region_selector');
        });
    }
};
