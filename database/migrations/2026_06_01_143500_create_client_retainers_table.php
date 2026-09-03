<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Periodized contracted hours per client. Each row represents the
        // retainer in force from `effective_from` (inclusive) up to but not
        // including `effective_to` (nullable = current/open-ended). Generating
        // a report snapshots the row active at period_start so historical
        // reports stay accurate after contract changes.
        Schema::create('client_retainers', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('client_id');
            $table->decimal('monthly_hours', 6, 2);
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->char('currency', 3)->default('PLN');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'effective_from']);
            $table->index(['client_id', 'effective_to']);

            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_retainers');
    }
};
