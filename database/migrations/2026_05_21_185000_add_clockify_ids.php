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
            // Opaque Clockify hex IDs (~24 chars). Null = not mirrored. Once
            // populated, the CRM uses this to address the matching Clockify
            // client without a name fuzzy-match.
            $table->string('clockify_client_id', 64)->nullable()->index()->after('lifecycle_stage');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('clockify_project_id', 64)->nullable()->index()->after('trello_url');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('clockify_client_id');
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('clockify_project_id');
        });
    }
};
