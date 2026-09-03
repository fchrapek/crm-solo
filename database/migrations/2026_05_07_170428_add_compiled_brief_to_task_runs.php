<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->json('compiled_brief')->nullable()->after('output_log');
            $table->json('cost_usage')->nullable()->after('compiled_brief');
        });
    }

    public function down(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropColumn(['compiled_brief', 'cost_usage']);
        });
    }
};
