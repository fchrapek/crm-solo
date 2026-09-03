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
        // Which monthly-close cohort a client belongs to (null = not closed
        // monthly). Drives both inclusion in the worklist and which checklist
        // template it gets:
        //   maintenance — abonament/retainer sites: full site-safety checklist.
        //   gig         — agency monthly work, no fixed package: lean checklist.
        // Orthogonal to lifecycle_stage.
        Schema::table('clients', function (Blueprint $table) {
            $table->string('month_close_type', 20)->nullable()->after('is_pinned');
        });

        // Reuse abonament: clients with an active (open-ended) retainer default
        // to the maintenance cohort.
        DB::table('clients')
            ->whereIn('id', function ($query) {
                $query->select('client_id')
                    ->from('client_retainers')
                    ->whereNull('effective_to');
            })
            ->update(['month_close_type' => 'maintenance']);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('month_close_type');
        });
    }
};
