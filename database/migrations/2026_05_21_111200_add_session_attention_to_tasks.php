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
            // Per-session secret the CLI hook sends back to authenticate POSTs
            // to /api/session-events/{token}. Regenerated on every launch,
            // cleared on stop — never reused across sessions.
            $table->string('session_token', 64)->nullable()->after('session_pid');
            // Set when the CLI's Notification hook fires (claude needs input /
            // idle > 60s). Cleared when the user visits the task page.
            $table->timestamp('session_attention_at')->nullable()->after('session_token');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['session_token', 'session_attention_at']);
        });
    }
};
