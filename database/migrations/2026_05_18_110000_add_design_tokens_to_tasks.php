<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tasks.design_tokens` — JSON list of design tokens extracted from the task's
 * Figma frames (typography, colors, spacing, etc.). Two tiers per row:
 *   - "strict"    — agent matches exactly (typography, color, radius, shadow)
 *   - "reference" — agent uses proportionally (raw px spacing, dimensions)
 *
 * Editable by the user before promoting — the dialog lets you delete/edit rows
 * the extractor got wrong. When set, the compiler injects them as verbatim
 * "DESIGN TOKENS" sections of the user prompt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->json('design_tokens')->nullable()->after('success_criteria');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('design_tokens');
        });
    }
};
