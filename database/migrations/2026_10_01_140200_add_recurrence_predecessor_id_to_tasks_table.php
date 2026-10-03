<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The task a recurring instance was spawned from, unique: a task has at most
 * one next instance however its interval changes later. Backfilled where an
 * existing child matches its parent's interval and is the only one that does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->unsignedInteger('recurrence_predecessor_id')->nullable()->after('parent_task_id');
            $table->unique('recurrence_predecessor_id');
        });

        $matches = DB::table('tasks as child')
            ->join('tasks as parent', 'parent.id', '=', 'child.parent_task_id')
            ->whereNotNull('parent.recurrence_period_days')
            ->whereColumn('child.recurrence_period_days', 'parent.recurrence_period_days')
            ->select('child.parent_task_id', DB::raw('MIN(child.id) as child_id'), DB::raw('COUNT(*) as matches'))
            ->groupBy('child.parent_task_id')
            ->get();

        foreach ($matches as $match) {
            if ((int) $match->matches === 1) {
                DB::table('tasks')->where('id', $match->child_id)->update(['recurrence_predecessor_id' => $match->parent_task_id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropUnique(['recurrence_predecessor_id']);
            $table->dropColumn('recurrence_predecessor_id');
        });
    }
};
