<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\HumanizedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The CRM-owned brief on a task, written by agents and confirmed by the
 * owner. CRM prose, so every field passes the humanizer on write. Nothing
 * anywhere waits on a confirmation.
 */
final class TaskBrief extends Model
{
    /** Record field => column. `where` is stored as `location`, a word SQL does not reserve. */
    public const array FIELDS = [
        'where' => 'location',
        'done_when' => 'done_when',
        'constraints' => 'constraints',
        'notes' => 'notes',
    ];

    public const string VIA_CLI = 'cli';

    public const string VIA_MCP = 'mcp';

    protected $fillable = [
        'task_id',
        'location',
        'done_when',
        'constraints',
        'notes',
        'drafted_by_user_id',
        'drafted_via',
        'drafted_at',
        'confirmations',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function draftedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'drafted_by_user_id');
    }

    public function value(string $field): ?string
    {
        $value = $this->getAttribute(self::FIELDS[$field]);

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function casts(): array
    {
        return [
            'location' => HumanizedText::class,
            'done_when' => HumanizedText::class,
            'constraints' => HumanizedText::class,
            'notes' => HumanizedText::class,
            'drafted_at' => 'datetime',
            'confirmations' => 'array',
        ];
    }
}
