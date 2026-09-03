<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One monthly-close worklist per maintained client per period. The CRM
        // only tracks checklist state here — the actual work (DB dumps, live
        // checks, backups) is done live with the agent. `period` is 'YYYY-MM'.
        Schema::create('month_close_runs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('client_id');
            $table->string('period', 7);
            // Cohort snapshotted at start so retagging the client later doesn't
            // rewrite a past run's checklist.
            $table->string('close_type', 20)->default('maintenance');
            $table->enum('status', ['open', 'completed'])->default('open');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'period']);
            $table->index(['account_id', 'period']);

            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('month_close_runs');
    }
};
