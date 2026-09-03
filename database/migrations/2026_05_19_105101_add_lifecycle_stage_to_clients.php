<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Standard CRM lifecycle (sales pipeline) on Client.
        // prospect    — initial contact, no offer out
        // offer_sent  — proposal/offer issued, awaiting decision
        // active      — signed / retainer running
        // paused      — temporary hold (vacation, project gap)
        // churned     — done / departed
        Schema::table('clients', function (Blueprint $table) {
            $table->string('lifecycle_stage', 32)->default('prospect')->after('is_pinned');
            $table->timestamp('lifecycle_stage_changed_at')->nullable()->after('lifecycle_stage');
        });

        // Existing clients are already real working relationships → active.
        DB::table('clients')->update([
            'lifecycle_stage' => 'active',
            'lifecycle_stage_changed_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['lifecycle_stage', 'lifecycle_stage_changed_at']);
        });
    }
};
