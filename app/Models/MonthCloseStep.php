<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MonthCloseStep extends Model
{
    public const string STATE_PENDING = 'pending';

    public const string STATE_DONE = 'done';

    public const string STATE_SKIPPED = 'skipped';

    protected $fillable = [
        'account_id',
        'month_close_run_id',
        'project_id',
        'step_key',
        'position',
        'state',
        'note',
        'completed_at',
        'completed_by',
    ];

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->where($field ?? 'id', $value)
            ->where('account_id', auth()->user()->account_id)
            ->firstOrFail();
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(MonthCloseRun::class, 'month_close_run_id');
    }

    /**
     * The site this step belongs to. Null for client-level steps (reconcile,
     * report, invoice), which happen once no matter how many sites there are.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }
}
