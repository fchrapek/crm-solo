<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A journal note can be about one task; it stays on the client's timeline and also shows on the task. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_lifecycle_events', function (Blueprint $table) {
            $table->unsignedInteger('task_id')->nullable();

            $table->index('task_id');
            $table->foreign('task_id')->references('id')->on('tasks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_lifecycle_events', function (Blueprint $table) {
            $table->dropForeign(['task_id']);
            $table->dropIndex(['task_id']);
            $table->dropColumn('task_id');
        });
    }
};
