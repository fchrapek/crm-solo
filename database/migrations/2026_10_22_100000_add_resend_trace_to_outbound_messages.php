<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A failed message sent again goes back to pending on the same row (the
 * unique rule, client and occurrence key allows one row), so the earlier
 * failure is kept here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->unsignedSmallInteger('resends')->default(0)->after('error');
            $table->string('previous_error')->nullable()->after('resends');
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->dropColumn(['resends', 'previous_error']);
        });
    }
};
