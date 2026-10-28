<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_report_revisions', function (Blueprint $table) {
            $table->timestamp('finalized_at_before')->nullable()->after('status_before');
            $table->timestamp('sent_at_before')->nullable()->after('finalized_at_before');
        });
    }

    public function down(): void
    {
        Schema::table('client_report_revisions', function (Blueprint $table) {
            $table->dropColumn(['finalized_at_before', 'sent_at_before']);
        });
    }
};
