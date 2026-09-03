<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gmail/email integration removed 2026-07-28 (docs/roadmap/
 * 2026-07-28-client-view-and-gmail-cut.md). Drops the email tables and the
 * email-only columns on other tables. Deliberately kept: projects.is_inbox
 * (delete-guard reads it), tasks.is_reviewed/review_* (ordinary task
 * creation writes is_reviewed), the 'email' value in tasks.source
 * (historical rows). DB backup taken the same day.
 */
return new class extends Migration
{
    public function up(): void
    {
        // FK out before its target table goes.
        if (Schema::hasColumn('tasks', 'source_email_id')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->dropForeign(['source_email_id']);
                $table->dropColumn('source_email_id');
            });
        }

        Schema::dropIfExists('email_associations');
        Schema::dropIfExists('emails');

        if (Schema::hasColumn('clients', 'sync_email_filter')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->dropColumn('sync_email_filter');
            });
        }

        if (Schema::hasColumn('tasks', 'suggested_project_id')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->dropIndex(['suggested_project_id']);
                $table->dropColumn(['suggested_project_id', 'suggested_project_reason']);
            });
        }
    }

    public function down(): void
    {
        // Irreversible by design — restore from the 2026-07-28 backup instead.
    }
};
