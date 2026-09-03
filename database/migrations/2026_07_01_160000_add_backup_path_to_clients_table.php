<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Base destination for this client's month-close backups (DB dump /
        // full-site copy). The dated `{YYYY}/{YYYYMMDD}/` subfolder is appended
        // at close time.
        Schema::table('clients', function (Blueprint $table) {
            $table->string('backup_path', 1024)->nullable()->after('report_mode');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('backup_path');
        });
    }
};
