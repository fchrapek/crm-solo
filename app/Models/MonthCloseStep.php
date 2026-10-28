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

    public const string VIA_AGENT = 'agent';

    public const string VIA_WEB = 'web';

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
        'completed_via',
    ];

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->where($field ?? 'id', $value)
            ->where('account_id', auth()->user()->account_id)
            ->firstOrFail();
    }

    /** The one write of a step's state, for the app and the agents alike; a null note keeps the one recorded earlier. */
    public function mark(string $state, ?User $actor, string $via, ?string $note = null): void
    {
        $pending = $state === self::STATE_PENDING;

        $this->update([
            'state' => $state,
            'completed_at' => $pending ? null : now(),
            'completed_by' => $pending ? null : $actor?->id,
            'completed_via' => $pending ? null : $via,
            ...($note === null ? [] : ['note' => $note]),
        ]);
        $this->run->refreshStatusFromSteps();
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
