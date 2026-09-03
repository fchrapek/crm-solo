<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SSH connection for this client's live server (host/alias or user@host),
        // used by the month-close live-check / dump steps. Not every client has
        // one (nullable).
        Schema::table('clients', function (Blueprint $table) {
            $table->string('ssh_config', 1024)->nullable()->after('backup_path');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('ssh_config');
        });
    }
};
