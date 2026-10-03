<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Files attached to tasks: uploaded in the CRM, or pulled from the task's
 * Trello card (trello_attachment_id set). Surfaced into the terminal session
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
        'trello_attachment_id',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** Pulled from the task's Trello card rather than uploaded in the CRM. */
    public function isFromTrello(): bool
    {
        return $this->trello_attachment_id !== null;
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
