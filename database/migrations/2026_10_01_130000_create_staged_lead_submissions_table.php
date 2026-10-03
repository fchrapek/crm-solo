<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every pulled form submission is written here with its payload before any
 * of the batch is imported, and leaves when its lead exists. A row that fails
 * to import stays with its error and attempt count, so a failed or killed run
 * loses nothing: the next run finds it here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staged_lead_submissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('account_id');
            $table->string('external_ref', 64);
            $table->json('payload');
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('given_up_at')->nullable();
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->unique(['account_id', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staged_lead_submissions');
    }
};
