<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_attachments', function (Blueprint $table) {
            // `context` = reference material the agent reads to understand the task
            //             (QA screenshots, PDF specs, copy payloads, design refs).
            // `block_asset` = an image the agent must upload to the WP media library
            //                 and use verbatim in a block's image field. Replaces
            //                 any Figma-image extraction for that field.
            $table->string('role', 32)->default('context')->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('task_attachments', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
