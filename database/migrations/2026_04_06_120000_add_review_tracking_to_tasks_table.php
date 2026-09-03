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
            $table->enum('review_action', ['approved', 'edited', 'rejected'])->nullable()->after('is_reviewed');
            $table->timestamp('reviewed_at')->nullable()->after('review_action');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['review_action', 'reviewed_at']);
        });
    }
};
