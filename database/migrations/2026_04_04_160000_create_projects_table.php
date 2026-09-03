<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('account_id')->index();
            $table->integer('client_id')->nullable()->index();
            $table->string('trello_board_id', 100)->nullable()->index();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('trello_url', 500)->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'trello_board_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
