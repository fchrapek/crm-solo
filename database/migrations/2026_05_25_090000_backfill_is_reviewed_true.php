<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // /tasks/review surface removed — any row still flagged as
        // unreviewed has no reachable triage UI, so mark them reviewed.
        DB::table('tasks')->where('is_reviewed', false)->update(['is_reviewed' => true]);
    }

    public function down(): void
    {
        // Intentional no-op: previous review state is not preserved.
    }
};
