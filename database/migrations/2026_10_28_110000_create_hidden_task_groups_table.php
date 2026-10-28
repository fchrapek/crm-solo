<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groups of tasks the owner keeps off the full task list: a whole project, or
 * one Trello list of a board project when `list_name` is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hidden_task_groups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('project_id');
            $table->string('list_name')->nullable();
            $table->string('group_key', 300);
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['account_id', 'group_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hidden_task_groups');
    }
};
