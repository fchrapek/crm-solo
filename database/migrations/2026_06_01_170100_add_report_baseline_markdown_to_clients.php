<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Markdown block listing the always-included monthly activities
            // (Monitoring / Testy / Infrastruktura / Aktualizacje). Injected
            // verbatim into every generated report WITHOUT hours. Separate
            // from offer_markdown because they have different lifecycles —
            // offer is the contract reference, baseline is what shows up in
            // every monthly report.
            $table->longText('report_baseline_markdown')->nullable()->after('offer_markdown');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('report_baseline_markdown');
        });
    }
};
