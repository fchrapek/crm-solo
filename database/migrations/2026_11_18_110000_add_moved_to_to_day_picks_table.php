<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('day_picks', function (Blueprint $table): void {
            $table->date('moved_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('day_picks', function (Blueprint $table): void {
            $table->dropColumn('moved_to');
        });
    }
};
