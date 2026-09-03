<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TaskSession extends Model
{
    public const string ENDED_STOPPED = 'stopped';

    public const string ENDED_CRASHED = 'crashed';

    protected $fillable = [
        'task_id',
        'account_id',
        'cli',
        'base_branch',
        'branch_name',
        'worktree_path',
        'time_entry_id',
        'started_at',
        'ended_at',
        'ended_reason',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
