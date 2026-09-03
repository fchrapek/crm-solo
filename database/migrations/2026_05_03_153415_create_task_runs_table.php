<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_runs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('task_id')->index();
            $table->string('status', 20)->default('pending'); // pending|running|success|failed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->longText('output_log')->nullable();
            $table->integer('exit_code')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_runs');
    }
};
