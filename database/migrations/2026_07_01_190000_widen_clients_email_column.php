<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 50 was too short: a single long address, or Infakt returning a
        // comma-separated list, overflowed and made the whole client sync fail
        // (SQLSTATE 22001). 191 is the standard safe email length.
        Schema::table('clients', function (Blueprint $table) {
            $table->string('email', 191)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('email', 50)->nullable()->change();
        });
    }
};
