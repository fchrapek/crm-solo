<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where this site's production dumps are filed, as the folder that
        // directly contains the {YYYY}/{YYYYMMDD} tree.
        //
        // This has to live per project, not per client: archive folder names
        // do not follow the CRM project names (a site's files may sit under a
        // shortened folder, a sub-project under a slug of its own), and the
        // depth varies — multi-site clients nest under a per-site folder,
        // single-site clients share one backup folder, and some keep the years
        // directly under their base. No rule derives it, so it gets written
        // down once instead of guessed on every run.
        //
        // clients.backup_path stays as the client's vault base, which is what a
        // new site's path is resolved against.
        Schema::table('projects', function (Blueprint $table) {
            $table->string('backup_path', 1024)->nullable()->after('include_in_month_close');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('backup_path');
        });
    }
};
