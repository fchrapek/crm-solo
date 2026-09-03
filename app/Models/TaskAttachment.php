<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User-uploaded files attached to tasks. Surfaced into the terminal session
 * via CRM_TASK.md absolute-path listings so the CLI agent can Read them.
 *
 * Files live under storage/app/private/task-attachments/{task_id}/.
 *
 * Account scoping is enforced via the parent task → project → account chain.
 */
final class TaskAttachment extends Model
{
    protected $fillable = [
        'task_id',
        'file_path',
        'original_name',
        'mime',
        'size',
        'label',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function isFigmaAsset(): bool
    {
        return str_starts_with((string) $this->label, 'Figma asset');
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }
}
