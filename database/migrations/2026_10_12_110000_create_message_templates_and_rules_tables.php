<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Text messages an account sends its clients: templates with placeholders, and
 * rules saying when (a field takes a value, or some time before a date field).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->string('key', 64);
            $table->string('name', 100);
            $table->text('body');
            $table->timestamps();

            $table->unique(['account_id', 'key']);
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
        });

        Schema::create('message_rules', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->string('key', 64);
            $table->unsignedInteger('template_id');
            $table->string('trigger_type', 32);
            $table->json('trigger_config');
            $table->unsignedInteger('expire_minutes')->default(120);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['account_id', 'key']);
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('template_id')->references('id')->on('message_templates')->restrictOnDelete();
        });

        Schema::create('outbound_messages', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('client_id');
            $table->unsignedInteger('rule_id')->nullable();
            $table->string('occurrence', 191);
            $table->string('channel', 16)->default('sms');
            $table->string('status', 16);
            $table->timestamp('scheduled_for');
            $table->timestamp('expires_at');
            $table->string('to', 32)->nullable();
            $table->text('body')->nullable();
            $table->string('provider', 32)->nullable();
            $table->string('provider_message_id', 100)->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('cancelled_by')->nullable();
            $table->timestamps();

            $table->unique(['rule_id', 'client_id', 'occurrence']);
            $table->index(['status', 'scheduled_for']);
            $table->index(['client_id', 'created_at']);
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('rule_id')->references('id')->on('message_rules')->nullOnDelete();
            $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('sms_opt_out')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('sms_opt_out');
        });
        Schema::dropIfExists('outbound_messages');
        Schema::dropIfExists('message_rules');
        Schema::dropIfExists('message_templates');
    }
};
