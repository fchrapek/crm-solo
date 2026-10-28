<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_month_closures', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('client_id');
            $table->char('period', 7);
            $table->timestamp('closed_at');
            $table->unsignedInteger('closed_by_user_id')->nullable();
            $table->json('snapshot');
            $table->timestamps();

            $table->unique(['client_id', 'period']);

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('closed_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_month_closures');
    }
};
