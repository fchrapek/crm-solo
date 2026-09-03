<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Command the project runs to serve itself in dev — `ddev start`,
            // `bun run dev`, `pnpm dev`, `rails s`, whatever. Stack-agnostic;
            // CRM just spawns it via ttyd in the worktree.
            $table->string('preview_command', 500)->nullable();
            // Relative to repo root. Empty/null = repo root. Sage themes
            // typically need this set to web/app/themes/<theme> so vite finds
            // its config; Node monorepos to apps/web; etc.
            $table->string('preview_working_dir', 255)->nullable();
            // Optional click-to-open hint. Most dev servers print their URL on
            // startup so this is just a convenience.
            $table->string('preview_url', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['preview_command', 'preview_working_dir', 'preview_url']);
        });
    }
};
