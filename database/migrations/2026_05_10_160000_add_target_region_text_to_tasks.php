<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `target_region_text` — optional text snippet to disambiguate
 * `target_region_selector` when the CSS selector matches multiple elements.
 *
 * The screenshot pipeline first resolves the selector, then (if text is set)
 * filters matches to those whose `textContent` contains the snippet
 * (case-insensitive), and finally picks the largest survivor by area. Without
 * this, a too-generic selector like `.section` could silently capture the
 * wrong region, which is exactly the failure this column was added for: a
 * BEFORE screenshot clipped the footer area instead of the credibility
 * section it was aimed at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('target_region_text', 200)->nullable()->after('target_region_selector');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('target_region_text');
        });
    }
};
