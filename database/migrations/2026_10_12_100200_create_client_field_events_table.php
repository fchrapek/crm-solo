<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every change of a client's custom field or assignee: old and new value, and
 * the label and displayed text as they read at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_field_events', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('client_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('field_key', 64);
            $table->string('label', 100);
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->text('old_display')->nullable();
            $table->text('new_display')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'created_at']);
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_field_events');
    }
};
