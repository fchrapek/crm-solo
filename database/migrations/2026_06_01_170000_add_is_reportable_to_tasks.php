<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Per-task flag: should this task's work appear in client reports?
            // Default false — internal tracking stays internal. Recurring
            // baseline tasks (monitoring, security, etc.) stay false; their
            // scope is mentioned via clients.report_baseline_markdown without
            // hours. Ad-hoc / project work flips this true and shows with
            // hours in the report.
            $table->boolean('is_reportable')->default(false)->after('is_reviewed');
            $table->index(['project_id', 'is_reportable']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'is_reportable']);
            $table->dropColumn('is_reportable');
        });
    }
};
