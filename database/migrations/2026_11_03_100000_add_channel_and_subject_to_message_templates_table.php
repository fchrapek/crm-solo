<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email templates live beside the text message ones; every existing template is a text message. One email template is the default for new drafts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table): void {
            $table->string('channel', 8)->default('sms')->after('key');
            $table->string('subject', 200)->nullable()->after('name');
            $table->boolean('is_default')->default(false)->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table): void {
            $table->dropColumn(['channel', 'subject', 'is_default']);
        });
    }
};
