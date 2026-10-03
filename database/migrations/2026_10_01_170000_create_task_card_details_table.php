<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A card's checklists, comments and attachment list, fetched when an agent
 * opens the task rather than on every sync. card_activity_at is the card's
 * dateLastActivity at fetch time: the cache is stale once the synced task
 * reports newer activity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_card_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('task_id')->unique();
            $table->json('checklists')->nullable();
            $table->json('comments')->nullable();
            $table->json('card_attachments')->nullable();
            $table->timestamp('card_activity_at')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_card_details');
    }
};
