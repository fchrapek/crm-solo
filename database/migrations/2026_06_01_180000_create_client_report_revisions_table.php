<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only audit log for ClientReport mutations. Every update,
        // regenerate, finalize, or reopen snapshots the BEFORE state into a
        // row so the change is reviewable and (eventually) restorable.
        // Finalized reports remain editable — the lock was removed in favor
        // of this audit trail.
        Schema::create('client_report_revisions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('client_report_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('reason', 32); // update | regenerate | finalize | reopen
            $table->longText('body_markdown_before')->nullable();
            $table->string('status_before', 32);
            $table->decimal('contracted_hours_before', 6, 2)->nullable();
            $table->decimal('actual_hours_before', 8, 2)->nullable();
            $table->char('currency_before', 3)->nullable();
            $table->string('composer_key_before', 64)->nullable();
            $table->timestamp('created_at');

            $table->index(['client_report_id', 'created_at']);

            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('client_report_id')->references('id')->on('client_reports')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_report_revisions');
    }
};
