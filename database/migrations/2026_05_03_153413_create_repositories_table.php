<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('project_id')->index();
            $table->string('name', 255);
            $table->string('local_path', 500)->nullable();
            $table->string('remote_url', 500)->nullable();
            $table->string('provider', 50)->default('local');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
