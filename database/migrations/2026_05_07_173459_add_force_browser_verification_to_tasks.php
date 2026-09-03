<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Column already added directly via Schema::table in the same session;
        // this file documents the change so a clean migrate from scratch picks it up.
        if (! Schema::hasColumn('tasks', 'force_browser_verification')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->boolean('force_browser_verification')->default(false)->after('agent_max_iterations');
            });
        }
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('force_browser_verification');
        });
    }
};
