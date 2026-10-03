<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why the last fetch of a card failed and when to try again: failures back
 * off instead of hitting Trello on every read, and a card that is gone stops
 * being fetched. A row may hold only this state, before any fetch succeeded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_card_details', function (Blueprint $table): void {
            $table->string('fetch_error')->nullable()->after('fetched_at');
            $table->unsignedSmallInteger('fetch_failures')->default(0)->after('fetch_error');
            $table->timestamp('retry_after')->nullable()->after('fetch_failures');
            $table->timestamp('card_gone_at')->nullable()->after('retry_after');
        });
    }

    public function down(): void
    {
        Schema::table('task_card_details', function (Blueprint $table): void {
            $table->dropColumn(['fetch_error', 'fetch_failures', 'retry_after', 'card_gone_at']);
        });
    }
};
