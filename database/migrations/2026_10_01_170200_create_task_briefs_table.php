<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CRM's own brief on a task, on top of whatever the card says: where to
 * work (`location`, read as `where`), when it is done, constraints and notes.
 * An agent may draft it; confirmations holds, per field, when the owner
 * confirmed it and who.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_briefs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('task_id')->unique();
            $table->text('location')->nullable();
            $table->text('done_when')->nullable();
            $table->text('constraints')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('drafted_by_user_id')->nullable();
            $table->string('drafted_via', 16)->nullable();
            $table->timestamp('drafted_at')->nullable();
            $table->json('confirmations')->nullable();
            $table->timestamps();

            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->foreign('drafted_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_briefs');
    }
};
