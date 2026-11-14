<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\HumanizedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A task's billed figure for one client and month, stated by the owner in place of its real time there; the note is for the owner only. */
final class TaskBilledMonth extends Model
{
    protected $fillable = [
        'task_id',
        'client_id',
        'period',
        'billed_minutes',
        'note',
        'updated_by_user_id',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'billed_minutes' => 'integer',
            'note' => HumanizedText::class,
        ];
    }
}
