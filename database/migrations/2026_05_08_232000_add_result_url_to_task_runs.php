<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            // Captured from the agent's final assistant text when the
            // return_result_url mandate was active. Surfaced as a clickable
            // "Open result" link in the after-run report so the user doesn't
            // have to dig through the log.
            $table->string('result_url', 2000)->nullable()->after('files_changed');
        });
    }

    public function down(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropColumn('result_url');
        });
    }
};
