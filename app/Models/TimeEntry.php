<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\HumanizedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TimeEntry extends Model
{
    public const string SOURCE_CLOCKIFY = 'clockify';

    public const string SOURCE_TERMINAL_SESSION = 'terminal_session';

    public const string SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'account_id',
        'project_id',
        'client_id',
        'task_id',
        'source',
        'clockify_entry_id',
        'title',
        'description',
        'start_time',
        'end_time',
        'duration_minutes',
        'billable',
        'tags',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected function casts(): array
    {
        return [
            'title' => HumanizedText::class,
            'description' => HumanizedText::class,
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'billable' => 'boolean',
            'tags' => 'array',
        ];
    }
}
