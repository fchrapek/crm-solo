<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The dump destination moved to projects.backup_path, where it has to
        // live: vault folders are not named after CRM projects and do not sit at
        // a constant depth. What was left here was a base path nothing wrote to,
        // and a second source of truth that went stale the first time a vault
        // folder was renamed while every site path stayed correct.
        //
        // month-close:map-backups now derives its search folder from the sites
        // already mapped, and takes --base for a client with none.
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('backup_path');
        });
    }

    public function down(): void
    {
        // Restores the column, not the values — the paths live on the projects.
        Schema::table('clients', function (Blueprint $table) {
            $table->string('backup_path', 1024)->nullable()->after('report_mode');
        });
    }
};
