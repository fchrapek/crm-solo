<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->string('sentiment', 20)->nullable()->index()->after('has_attachments');
            $table->decimal('sentiment_score', 3, 2)->nullable()->after('sentiment');
            $table->json('sentiment_labels')->nullable()->after('sentiment_score');
            $table->timestamp('sentiment_analyzed_at')->nullable()->after('sentiment_labels');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropColumn(['sentiment', 'sentiment_score', 'sentiment_labels', 'sentiment_analyzed_at']);
        });
    }
};
