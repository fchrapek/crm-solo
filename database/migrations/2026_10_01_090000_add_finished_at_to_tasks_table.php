<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "My work is finished", owned by the CRM. For a Trello card `is_completed`
 * and `list_name` belong to Trello and are rewritten by every sync, so the
 * owner's tick needs a column the sync does not own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->timestamp('finished_at')->nullable()->after('is_completed')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex(['finished_at']);
            $table->dropColumn('finished_at');
        });
    }
};
