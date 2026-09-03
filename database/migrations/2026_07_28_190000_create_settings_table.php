<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User-editable overrides layered on top of shipped config defaults — the
 * productization pattern: config/*.php = defaults, this table = what the
 * user customized (first consumer: leadgen custom sources).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            // Parent accounts use legacy int(10) unsigned ids — FK must match
            // (unsignedInteger, never foreignId's bigint).
            $table->unsignedInteger('account_id');
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->string('scope', 64);
            $table->json('data');
            $table->timestamps();

            $table->unique(['account_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
