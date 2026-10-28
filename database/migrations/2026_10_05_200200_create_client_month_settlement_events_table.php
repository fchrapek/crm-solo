<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_month_settlement_events', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('client_id');
            $table->char('period', 7);
            $table->string('action', 16);
            // No foreign key: a reopen deletes the closure its event names.
            $table->unsignedInteger('closure_id')->nullable();
            $table->json('snapshot');
            $table->unsignedInteger('actor_user_id')->nullable();
            $table->timestamp('created_at');

            $table->index(['client_id', 'period']);

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_month_settlement_events');
    }
};
