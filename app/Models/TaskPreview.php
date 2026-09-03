<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TaskPreview extends Model
{
    public const string STOPPED_NORMAL = 'stopped';

    public const string STOPPED_CRASHED = 'crashed';

    protected $fillable = [
        'task_id',
        'project_id',
        'account_id',
        'pid',
        'port',
        'command',
        'working_dir',
        'url',
        'log_path',
        'started_at',
        'stopped_at',
        'stopped_reason',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'stopped_at' => 'datetime',
        ];
    }
}
