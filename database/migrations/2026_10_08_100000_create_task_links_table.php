<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links added to a task in the CRM (a staging page, a design file), listed
 * with the task's files under Zasoby and given to agents in `crm task`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_links', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('task_id');
            $table->string('url', 2048);
            $table->string('label', 200)->nullable();
            $table->timestamps();

            $table->index('task_id');
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_links');
    }
};
