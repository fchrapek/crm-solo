<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a step was moved by an agent or in the app: an agent acts as its owner's user, so completed_by cannot tell.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('month_close_steps', function (Blueprint $table): void {
            $table->string('completed_via', 8)->nullable()->after('completed_by');
        });
    }

    public function down(): void
    {
        Schema::table('month_close_steps', function (Blueprint $table): void {
            $table->dropColumn('completed_via');
        });
    }
};
