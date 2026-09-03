<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tasks.success_criteria` — user-authored "what done looks like" for an
 * agent task. When set, the compiler uses it verbatim instead of deriving
 * its own. Empty for tasks where the user is happy to let the AI infer.
 *
 * Stored on the task row (not nested in `compiled_brief._meta`) so it's
 * editable independently of the compile cycle and survives re-compiles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->text('success_criteria')->nullable()->after('agent_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('success_criteria');
        });
    }
};
