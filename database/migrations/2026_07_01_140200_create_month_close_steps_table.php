<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Individual checklist items on a month-close run. Seeded from
        // MonthCloseRun::STEPS on run creation; each is toggled pending →
        // done / skipped by the user (or, later, by the agent via endpoint).
        Schema::create('month_close_steps', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('month_close_run_id');
            $table->string('step_key', 64);
            $table->unsignedSmallInteger('position')->default(0);
            $table->enum('state', ['pending', 'done', 'skipped'])->default('pending');
            $table->text('note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('completed_by')->nullable();
            $table->timestamps();

            $table->unique(['month_close_run_id', 'step_key']);

            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('month_close_run_id')->references('id')->on('month_close_runs')->cascadeOnDelete();
            $table->foreign('completed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('month_close_steps');
    }
};
