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
            // Per-task safety: when true, executor appends a mandate to the
            // agent prompt requiring the agent to return the public URL where
            // the work can be verified (e.g. https://example.ddev.site/?p=42).
            // Lets the user click straight from the run log to verify.
            $table->boolean('return_result_url')->default(false)->after('compile_brief');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('return_result_url');
        });
    }
};
