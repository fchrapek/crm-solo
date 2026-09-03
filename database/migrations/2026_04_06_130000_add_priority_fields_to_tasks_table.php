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
            $table->enum('priority', ['low', 'medium', 'high'])->nullable()->after('labels');
            $table->enum('ai_priority', ['low', 'medium', 'high'])->nullable()->after('priority');
            $table->text('ai_priority_reasoning')->nullable()->after('ai_priority');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['priority', 'ai_priority', 'ai_priority_reasoning']);
        });
    }
};
