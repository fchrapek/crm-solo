<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each send of a draft becomes a numbered attempt, claimed by one guarded
 * statement, and an attempt in flight holds a lease. Results are written only
 * for the attempt they belong to. The old pending state splits in two:
 * submitted (Infakt gave a task reference) and unconfirmed (it never did).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('infakt_draft_requests', function (Blueprint $table): void {
            $table->unsignedInteger('attempt')->default(0)->after('status');
            $table->timestamp('lease_expires_at')->nullable()->after('attempt');
        });

        DB::table('infakt_draft_requests')->update(['attempt' => 1]);
        DB::table('infakt_draft_requests')->where('status', 'pending')->whereNotNull('task_reference')->update(['status' => 'submitted']);
        DB::table('infakt_draft_requests')->where('status', 'pending')->update(['status' => 'unconfirmed']);
    }

    public function down(): void
    {
        DB::table('infakt_draft_requests')->whereIn('status', ['submitted', 'sending'])->update(['status' => 'pending']);

        Schema::table('infakt_draft_requests', function (Blueprint $table): void {
            $table->dropColumn(['attempt', 'lease_expires_at']);
        });
    }
};
