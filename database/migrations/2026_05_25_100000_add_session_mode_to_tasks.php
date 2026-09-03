<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            // 'worktree' = isolated git worktree under <repo>/.worktrees/task-{id}/ (existing behavior).
            // 'in_repo'  = checkout session/task-{id} directly in the main repo working tree —
            //              avoids worktree-portability gotchas (.env / wp-config.php /
            //              node_modules not copied, WP auto-updater mutating worktree, …)
            //              at the cost of one-session-per-repo serialization.
            $table->enum('session_mode', ['worktree', 'in_repo'])
                ->default('worktree')
                ->after('session_attention_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('session_mode');
        });
    }
};
