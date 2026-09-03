<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tasks.type` distinguishes the three task shapes the dialog handles:
 *  - `general` (default) — light-touch tasks (refactor, docs, copy edit).
 *  - `feature` — new functionality / block from design (Figma frame + spec).
 *  - `bug` — broken behavior on an existing page (issue URL + screenshots).
 *
 * Surfaces different fields in `<AgentBriefDialog>` per type so the form
 * matches the task shape instead of mixing bug + feature context.
 *
 * Plain VARCHAR not ENUM: SQLite testing harness doesn't support enum mods,
 * and we already constrain via PHP-side `Task::TYPES`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('type', 32)->default('general')->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
