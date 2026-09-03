<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Per-task knob: when false, CompileTaskBriefJob skips the Haiku
            // compile step and writes a raw passthrough brief (agent_prompt
            // = user's text verbatim). For content-heavy tasks where the
            // user pasted an article body / config / large code snippet
            // and doesn't want a model rewriting it.
            $table->boolean('compile_brief')->default(true)->after('force_browser_verification');

            // Which model the compiler should use when compile_brief=true.
            // Null = haiku (default). Otherwise: 'haiku' / 'sonnet' / 'opus'.
            $table->string('brief_compiler_model', 32)->nullable()->after('compile_brief');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['compile_brief', 'brief_compiler_model']);
        });
    }
};
