<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_billed_months', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('task_id');
            $table->unsignedInteger('client_id');
            $table->char('period', 7);
            $table->unsignedInteger('billed_minutes');
            $table->text('note')->nullable();
            $table->unsignedInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['task_id', 'client_id', 'period']);
            $table->index(['client_id', 'period']);

            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('updated_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_billed_months');
    }
};
