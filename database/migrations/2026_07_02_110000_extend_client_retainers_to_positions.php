<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Generalize the single-retainer-per-client model into "abonament
     * positions": many active rows per client, each optionally scoped to a
     * project, each carrying its own hours/fee/overage + an invoice grouping.
     * A flat maintenance/subscription line is just a position with 0 hours;
     * an hours package is a position with hours. This absorbs the short-lived
     * client_maintenance_lines table (dropped in a follow-up step).
     */
    public function up(): void
    {
        Schema::table('client_retainers', function (Blueprint $table) {
            $table->unsignedInteger('project_id')->nullable()->after('client_id');
            $table->string('label')->nullable()->after('project_id');
            $table->text('description')->nullable()->after('label');
            $table->unsignedSmallInteger('invoice_group')->default(1)->after('overage_hourly_rate');
            $table->string('vat_symbol', 8)->default('23')->after('invoice_group');
            $table->decimal('rollover_cap_hours', 6, 2)->nullable()->after('vat_symbol');
            $table->boolean('is_active')->default(true)->after('rollover_cap_hours');
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');

            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_retainers', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropColumn([
                'project_id', 'label', 'description', 'invoice_group',
                'vat_symbol', 'rollover_cap_hours', 'is_active', 'sort_order',
            ]);
        });
    }
};
