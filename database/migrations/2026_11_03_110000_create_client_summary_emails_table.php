<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One month-end summary email per client and month: the draft, then the one
 * guarded send, then what was sent and when. The states are documented on
 * App\Services\Messaging\SummaryEmails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_summary_emails', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('client_id');
            $table->char('period', 7);
            $table->unsignedInteger('template_id')->nullable();
            $table->json('recipients');
            $table->boolean('bcc_owner')->default(true);
            $table->string('bcc_address')->nullable();
            $table->string('subject', 200);
            $table->text('body');
            $table->timestamp('drafted_at')->nullable();
            $table->string('drafted_via', 8)->nullable();
            $table->timestamp('text_edited_at')->nullable();
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('attempt')->default(0);
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('handed_off_at')->nullable();
            $table->string('delivery', 8)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('sent_by_user_id')->nullable();
            $table->string('message_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('step_ticked_at')->nullable();
            $table->text('tick_error')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'period']);
            $table->index(['account_id', 'status']);
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('template_id')->references('id')->on('message_templates')->nullOnDelete();
            $table->foreign('sent_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_summary_emails');
    }
};
