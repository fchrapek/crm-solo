<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\HumanizedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link added to a task in the CRM. The page it points at was written
 * outside the CRM, so agents read it under `untrusted`.
 *
 * @property int $id
 * @property int $task_id
 * @property string $url
 * @property string|null $label
 */
final class TaskLink extends Model
{
    protected $fillable = [
        'task_id',
        'url',
        'label',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    protected function casts(): array
    {
        return [
            'label' => HumanizedText::class,
        ];
    }
}
