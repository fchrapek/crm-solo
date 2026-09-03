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
            // segment = commercial classification (independent of legal `type`).
            // cooperation_type = how we bill / engage (retainer vs ad-hoc vs scoped).
            // Both nullable so existing rows stay valid; values constrained at the
            // model level via Client::SEGMENTS / Client::COOPERATION_TYPES.
            $table->string('segment', 32)->nullable()->after('lifecycle_stage');
            $table->string('cooperation_type', 32)->nullable()->after('segment');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['segment', 'cooperation_type']);
        });
    }
};
