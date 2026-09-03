<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `extracted_content` — structured payload extracted from a design source
 * (typically Figma) at planning time. Array of `{label, value}` rows for text
 * copy (headlines, body, CTAs, microcopy). Image/icon assets are stored as
 * regular `task_attachments` rows with a label prefix like "Figma:" so they
 * surface alongside other ground-truth files.
 *
 * Editable via `<AgentBriefDialog>` in the Context section so a wrong
 * extraction costs $0.10 to fix in the UI vs $1-3 in a wasted agent run.
 * Injected into the agent prompt as a TEXT CONTENT block inside the existing
 * GROUND TRUTH section.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->json('extracted_content')->nullable()->after('target_region_text');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('extracted_content');
        });
    }
};
