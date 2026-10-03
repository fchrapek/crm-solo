<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The first backfill of recurrence_predecessor_id needed parent and child to
 * share an interval, so a successor whose parent's interval changed later
 * stayed unlinked and the parent could spawn a second one. This links a
 * child that looks like a spawned instance (same name, manual, recurring,
 * not linked yet) to a parent with no linked successor, when it is the only
 * such child. Ambiguous parents stay unlinked.
 */
return new class extends Migration
{
    public function up(): void
    {
        $candidates = DB::table('tasks as child')
            ->join('tasks as parent', 'parent.id', '=', 'child.parent_task_id')
            ->whereColumn('child.name', 'parent.name')
            ->where('child.source', 'manual')
            ->whereNotNull('child.recurrence_period_days')
            ->whereNull('child.recurrence_predecessor_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('tasks as linked')->whereColumn('linked.recurrence_predecessor_id', 'parent.id'))
            ->select('child.parent_task_id', DB::raw('MIN(child.id) as child_id'), DB::raw('COUNT(*) as matches'))
            ->groupBy('child.parent_task_id')
            ->get();

        foreach ($candidates as $candidate) {
            if ((int) $candidate->matches === 1) {
                DB::table('tasks')
                    ->where('id', $candidate->child_id)
                    ->whereNull('recurrence_predecessor_id')
                    ->update(['recurrence_predecessor_id' => $candidate->parent_task_id]);
            }
        }
    }

    public function down(): void
    {
        // Links made here and by spawns are indistinguishable; the column's own migration drops them all.
    }
};
