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
            // Ralph-style iteration cap. 1 = single shot (default, today's behavior).
            // Higher = loop until agent emits AGENT_DONE or this cap is hit.
            // Hard-capped to 10 in app-level validation to prevent runaway runs.
            $table->unsignedTinyInteger('agent_max_iterations')->default(1)->after('agent_repository_id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('agent_max_iterations');
        });
    }
};
