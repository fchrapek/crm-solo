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
            $table->integer('recurrence_period_days')->nullable()->after('priority');
            $table->integer('parent_task_id')->nullable()->index()->after('recurrence_period_days');
            $table->string('executor_type', 100)->nullable()->after('parent_task_id');
            $table->json('executor_config')->nullable()->after('executor_type');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['recurrence_period_days', 'parent_task_id', 'executor_type', 'executor_config']);
        });
    }
};
