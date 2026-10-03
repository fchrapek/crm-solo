<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('day_picks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('task_id');
            $table->date('date');
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->unique(['account_id', 'date', 'task_id']);
            $table->index(['account_id', 'date']);
        });

        Schema::create('day_closes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('account_id');
            $table->date('date');
            $table->timestamp('closed_at');
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->unique(['account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_closes');
        Schema::dropIfExists('day_picks');
    }
};
