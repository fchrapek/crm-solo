<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('account_id')->index();
            $table->integer('project_id')->nullable()->index();
            $table->integer('client_id')->nullable()->index();
            $table->string('clockify_entry_id', 100)->nullable()->index();
            $table->string('description', 500)->nullable();
            $table->timestamp('start_time');
            $table->timestamp('end_time')->nullable();
            $table->integer('duration_minutes')->default(0);
            $table->boolean('billable')->default(true);
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'clockify_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
