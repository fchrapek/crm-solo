<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which scoring factors are ticked for this lead, e.g.
        // {"fit": ["budget-signal-5k"], "behaviour": ["magnet-download"]}.
        //
        // ONLY the factor slugs are stored. Points and tier are derived at
        // read time from config('leadgen.pipelines.*.scoring') + tiers, never
        // written to a column: the plan re-tunes its point weights (the
        // Month-2 checkpoint explicitly re-runs the back-solve), and a stored
        // total would freeze yesterday's maths into rows nobody re-computes.
        Schema::table('leads', function (Blueprint $table) {
            $table->json('score_factors')->nullable()->after('stage');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('score_factors');
        });
    }
};
