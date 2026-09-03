<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_associations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('email_id')->index();
            $table->integer('associable_id');
            $table->string('associable_type', 50);
            $table->string('matched_email', 255);
            $table->enum('match_type', ['auto', 'manual'])->default('auto');
            $table->timestamps();

            $table->index(['associable_type', 'associable_id']);
            $table->unique(['email_id', 'associable_type', 'associable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_associations');
    }
};
