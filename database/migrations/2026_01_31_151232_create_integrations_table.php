<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('account_id')->index();
            $table->string('provider', 50); // e.g., 'infakt', 'fakturownia', etc.
            $table->boolean('is_enabled')->default(false);
            $table->text('api_key')->nullable(); // encrypted
            $table->json('settings')->nullable(); // provider-specific settings
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
