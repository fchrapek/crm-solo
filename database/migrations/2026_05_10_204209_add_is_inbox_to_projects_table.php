<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('is_inbox')->default(false)->after('settings');
            $table->index(['account_id', 'is_inbox']);
        });

        // Backfill: one Inbox project per existing account.
        Account::query()->each(function (Account $account) {
            Project::firstOrCreate(
                ['account_id' => $account->id, 'is_inbox' => true],
                [
                    'client_id' => null,
                    'name' => 'Inbox',
                    'description' => 'Auto-collected tasks from emails awaiting routing.',
                ],
            );
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'is_inbox']);
            $table->dropColumn('is_inbox');
        });
    }
};
