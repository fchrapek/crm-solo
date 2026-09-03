<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `agent_base_branch` — git branch the executor cuts the agent branch from.
 * Defaults to NULL (= use repo's current HEAD, legacy behavior). When set,
 * `LaunchRunGroupAction` checks out this branch before reading baseline so
 * agent edits land on a branch off the chosen base (typical use: a feature
 * branch under active development that the task is meant to extend).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('agent_base_branch', 255)->nullable()->after('agent_repository_id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('agent_base_branch');
        });
    }
};
