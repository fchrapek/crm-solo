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
            // When a terminal session is live, ttyd serves the embedded terminal
            // on `session_port` and its OS process is tracked by `session_pid` so
            // we can stop/restart deterministically. Both null = no session running.
            $table->unsignedInteger('session_port')->nullable()->after('cli');
            $table->unsignedInteger('session_pid')->nullable()->after('session_port');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['session_port', 'session_pid']);
        });
    }
};
