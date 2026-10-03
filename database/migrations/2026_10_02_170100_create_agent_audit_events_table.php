<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per record an agent verb or tool writes: who (user, token), through
 * what (via, verb), in which caller session, and what changed. The `before`
 * values make a per-session undo possible later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('actor', 191);
            $table->string('via', 16);
            $table->string('session_id', 128)->nullable();
            $table->string('verb', 64);
            $table->string('action', 16);
            $table->string('target_type', 64);
            $table->unsignedBigInteger('target_id');
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['account_id', 'created_at']);
            $table->index(['session_id']);
            $table->index(['target_type', 'target_id']);
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('token_id')->references('id')->on('personal_access_tokens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_audit_events');
    }
};
