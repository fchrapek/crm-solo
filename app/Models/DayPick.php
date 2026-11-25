<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * One task picked for one day. DayPlanner::MAX_PICKS make a full day. A pick moved to another day stays as the
 * day's history (`moved_to`) and is no longer part of its plan.
 */
final class DayPick extends Model
{
    protected $fillable = ['account_id', 'task_id', 'date', 'slot', 'position', 'dismissed_at', 'moved_to'];

    /** Still on its day's plan. */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('moved_to'));
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return self::where('account_id', Auth::user()?->account_id)
            ->where($field ?? 'id', $value)
            ->firstOrFail();
    }

    protected function casts(): array
    {
        return ['date' => 'date', 'dismissed_at' => 'datetime', 'moved_to' => 'date'];
    }
}
