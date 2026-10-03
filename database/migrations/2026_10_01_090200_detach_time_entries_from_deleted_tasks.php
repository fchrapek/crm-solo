<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Time entries pointing at a task that no longer exists are detached the way
 * Task::deleting now detaches them; project and client stay, so the time is
 * still billed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('time_entries')
            ->whereNotNull('task_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('tasks')->whereColumn('tasks.id', 'time_entries.task_id'))
            ->update(['task_id' => null]);
    }

    public function down(): void
    {
        // The deleted task ids are gone; nothing to restore.
    }
};
