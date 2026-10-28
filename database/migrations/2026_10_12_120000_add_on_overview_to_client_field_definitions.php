<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which custom fields the client's Przegląd and the Klienci list show. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_field_definitions', function (Blueprint $table) {
            $table->boolean('on_overview')->default(false)->after('required');
        });
    }

    public function down(): void
    {
        Schema::table('client_field_definitions', function (Blueprint $table) {
            $table->dropColumn('on_overview');
        });
    }
};
