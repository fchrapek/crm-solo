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
            $table->unsignedBigInteger('suggested_project_id')->nullable()->after('project_id');
            $table->text('suggested_project_reason')->nullable()->after('suggested_project_id');
            $table->index('suggested_project_id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['suggested_project_id']);
            $table->dropColumn(['suggested_project_id', 'suggested_project_reason']);
        });
    }
};
