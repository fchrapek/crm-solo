<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renames the executor key `claude_code_live` → `agent_live`. The executor is
 * provider-agnostic now (Claude + OpenAI behind AgentProvider), so the
 * Claude-specific name is misleading. One executor key, no compat shim.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')
            ->where('executor_type', 'claude_code_live')
            ->update(['executor_type' => 'agent_live']);
    }

    public function down(): void
    {
        DB::table('tasks')
            ->where('executor_type', 'agent_live')
            ->update(['executor_type' => 'claude_code_live']);
    }
};
