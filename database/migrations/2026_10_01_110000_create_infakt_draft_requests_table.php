<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per maintenance draft the CRM asks Infakt for: client, billed month
 * and invoice group, unique together. Infakt creates drafts asynchronously, so
 * the row is written before the request and is what a second run checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infakt_draft_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('client_id');
            $table->char('period', 7);
            $table->unsignedSmallInteger('invoice_group');
            $table->string('status', 16);
            $table->string('task_reference', 64)->nullable();
            $table->string('invoice_uuid', 64)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->unique(['client_id', 'period', 'invoice_group']);
            $table->index(['account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infakt_draft_requests');
    }
};
