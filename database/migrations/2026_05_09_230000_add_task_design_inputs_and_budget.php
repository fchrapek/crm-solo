<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // The page where the bug actually appears. Captured here so the
            // executor doesn't have to guess (project DDEV root + heuristics).
            $table->string('issue_url', 2000)->nullable()->after('description');

            // Per-task override of `agent.max_budget_usd`. Visual / region tasks
            // tend to need a higher cap than the global default (Woodmart cascade
            // fights regularly hit $1.10-1.30 even after the compiler).
            $table->decimal('max_budget_usd', 5, 2)->nullable()->after('group_max_budget_usd');
        });

        Schema::create('task_design_references', function (Blueprint $table) {
            $table->id();
            $table->integer('task_id')->index();
            // mobile | desktop | generic — what viewport this ref represents.
            // V1 surfaces two slots in the UI, with `generic` reserved for
            // designs that aren't viewport-specific (style guides, tokens).
            $table->string('viewport', 16);
            // figma | (future: penpot, sketch, ...) — the design tool. Keeps the
            // resolver/registry seam open for non-Figma providers without schema
            // changes.
            $table->string('provider', 32);
            $table->string('url', 2000);
            $table->string('file_key', 200)->nullable();
            $table->string('node_id', 200)->nullable();
            $table->string('label', 200)->nullable();
            // Cached resolver payload (screenshot path, code hint, dimensions,
            // tokens). Populated by the FigmaDesignReferenceProvider on first
            // fetch; refreshed via the "Refresh" button on the UI.
            $table->json('cached_payload')->nullable();
            $table->timestamp('cached_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_design_references');
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['issue_url', 'max_budget_usd']);
        });
    }
};
