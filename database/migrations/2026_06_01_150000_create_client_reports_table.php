<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-client weekly/monthly retainer reports. Hours and the
        // composer's markdown body are snapshotted at generation time so
        // deleting time entries or editing the retainer later doesn't
        // silently mutate finalized reports. Multiple reports can target
        // the same period (regenerate-as-new); the UI lists newest first.
        Schema::create('client_reports', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('client_id');
            $table->enum('period_type', ['week', 'month']);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('contracted_hours', 6, 2)->nullable();
            $table->decimal('actual_hours', 8, 2)->default(0);
            $table->char('currency', 3)->nullable();
            $table->string('composer_key', 64);
            $table->longText('body_markdown')->nullable();
            $table->enum('status', ['draft', 'finalized', 'sent'])->default('draft');
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'period_start']);
            $table->index(['client_id', 'status']);

            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_reports');
    }
};
