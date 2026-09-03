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
            // null = regular task (no terminal-session affordance).
            // 'claude' | 'codex' = terminal-session task; appears on the agent kanban,
            // Start session button opens a worktree + launches the matching CLI.
            $table->string('cli', 16)->nullable()->after('agent_lane');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('cli');
        });
    }
};
