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
            $table->boolean('agent_done')->default(false)->after('exit_code');
            $table->string('commit_hash', 40)->nullable()->after('agent_done');
            $table->string('branch_name')->nullable()->after('commit_hash');
            $table->json('files_changed')->nullable()->after('branch_name');
            $table->unsignedSmallInteger('iterations_completed')->default(0)->after('files_changed');
        });
    }

    public function down(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropColumn(['agent_done', 'commit_hash', 'branch_name', 'files_changed', 'iterations_completed']);
        });
    }
};
