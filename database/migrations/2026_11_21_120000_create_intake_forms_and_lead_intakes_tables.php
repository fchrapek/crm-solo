<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_forms', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->string('name', 120);
            $table->char('public_key', 32)->unique();
            $table->json('allowed_origins');
            $table->string('pipeline', 50);
            $table->string('source', 50);
            $table->string('notify_to')->nullable();
            $table->text('turnstile_secret')->nullable();
            $table->char('webhook_secret_hash', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
        });

        // One row per accepted submission: its replay key and the outbox state of its lead notice.
        Schema::create('lead_intakes', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('intake_form_id');
            $table->unsignedInteger('lead_id')->nullable();
            $table->string('idempotency_key', 64);
            $table->char('fingerprint', 64);
            $table->string('notice_state', 20)->default('waiting');
            $table->unsignedSmallInteger('notify_attempts')->default(0);
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['intake_form_id', 'idempotency_key']);
            $table->index('notice_state');
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('intake_form_id')->references('id')->on('intake_forms')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_intakes');
        Schema::dropIfExists('intake_forms');
    }
};
