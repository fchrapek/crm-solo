<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fields an account adds to its clients (a status, a date, a choice list),
 * defined in the account rather than in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_field_definitions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('account_id');
            $table->string('key', 64);
            $table->string('label', 100);
            $table->string('type', 16);
            $table->json('options')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('required')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'key']);
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_field_definitions');
    }
};
