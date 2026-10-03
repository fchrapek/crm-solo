<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            // Set only by LeadCapture::captureOnce(); NULLs never collide, so other leads are unaffected.
            $table->char('capture_key', 64)->nullable()->after('external_ref');
            $table->unique(['account_id', 'capture_key']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropUnique(['account_id', 'capture_key']);
            $table->dropColumn('capture_key');
        });
    }
};
