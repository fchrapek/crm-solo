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
            $table->boolean('is_agent_ready')->default(false)->after('executor_config');
            $table->text('agent_prompt')->nullable()->after('is_agent_ready');
            $table->string('agent_lane')->nullable()->after('agent_prompt');
            // repositories.id is unsignedInteger (legacy), match it instead of using foreignId() which would create bigint
            $table->unsignedInteger('agent_repository_id')->nullable()->after('agent_lane');

            $table->foreign('agent_repository_id')->references('id')->on('repositories')->nullOnDelete();
            $table->index(['project_id', 'is_agent_ready', 'agent_lane'], 'tasks_agent_board_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_agent_board_idx');
            $table->dropForeign(['agent_repository_id']);
            $table->dropColumn(['is_agent_ready', 'agent_prompt', 'agent_lane', 'agent_repository_id']);
        });
    }
};
