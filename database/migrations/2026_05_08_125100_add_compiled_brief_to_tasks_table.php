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
            $table->json('compiled_brief')->nullable()->after('agent_repository_id');
            $table->string('brief_status', 16)->nullable()->after('compiled_brief');
            $table->timestamp('brief_compiled_at')->nullable()->after('brief_status');
            $table->text('brief_error')->nullable()->after('brief_compiled_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['compiled_brief', 'brief_status', 'brief_compiled_at', 'brief_error']);
        });
    }
};
