<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('project_id')->index();
            $table->string('trello_card_id', 100)->nullable()->index();
            $table->string('name', 500);
            $table->text('description')->nullable();
            $table->string('list_name', 255)->nullable();
            $table->integer('position')->default(0);
            $table->timestamp('due_date')->nullable();
            $table->json('labels')->nullable();
            $table->string('trello_url', 500)->nullable();
            $table->boolean('is_completed')->default(false);
            $table->timestamps();

            $table->unique(['project_id', 'trello_card_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
