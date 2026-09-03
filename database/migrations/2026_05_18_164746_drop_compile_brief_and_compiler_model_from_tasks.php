<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The under-the-hood AI compile step is gone (replaced by the visible
 * per-field Polish-with-AI surface). Both columns it depended on lose
 * their meaning:
 *   - compile_brief was the user opt-out from the AI rewrite. There is
 *     no rewrite anymore, so every brief is now what used to be called
 *     "raw passthrough". Dropping the flag.
 *   - brief_compiler_model picked which AI model performed the compile
 *     (haiku/sonnet/opus). Also gone with the compile step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'compile_brief')) {
                $table->dropColumn('compile_brief');
            }
            if (Schema::hasColumn('tasks', 'brief_compiler_model')) {
                $table->dropColumn('brief_compiler_model');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('compile_brief')->default(true);
            $table->string('brief_compiler_model')->nullable();
        });
    }
};
