<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One row per account that DayPlanner locks for every plan change; nothing references it, so no insert elsewhere ever waits on it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('day_plan_locks', function (Blueprint $table): void {
            $table->unsignedInteger('account_id')->primary();
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_plan_locks');
    }
};
