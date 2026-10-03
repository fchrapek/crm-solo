<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * One task picked for one day. Up to DayPlanner::MAX_PICKS per day.
 */
final class DayPick extends Model
{
    protected $fillable = ['account_id', 'task_id', 'date', 'slot', 'position'];

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
        return ['date' => 'date'];
    }
}
