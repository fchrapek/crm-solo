<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cached detail of a Trello card: checklists, comments and the card's
 * attachment list with what became of each file. Card text is stored as
 * Trello returned it, never humanized: it is third-party data.
 */
final class TaskCardDetails extends Model
{
    /** The columns a task list loads: what readiness reads, never the JSON. */
    public const array READINESS_COLUMNS = ['id', 'task_id', 'checklist_items', 'has_link_attachment'];

    protected $table = 'task_card_details';

    protected $fillable = [
        'task_id',
        'checklists',
        'comments',
        'card_attachments',
        'checklist_items',
        'has_link_attachment',
        'card_activity_at',
        'fetched_at',
        'fetch_error',
        'fetch_failures',
        'retry_after',
        'card_gone_at',
    ];

    /** Seconds to wait after the nth failure in a row: five minutes, doubling, at most six hours. */
    public static function backoffSeconds(int $failures): int
    {
        return (int) min(300 * 2 ** max(0, $failures - 1), 6 * 3600);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    protected static function booted(): void
    {
        // The readiness columns always follow the JSON they summarise, whoever writes it.
        self::saving(function (TaskCardDetails $details): void {
            if ($details->isDirty('checklists')) {
                $details->checklist_items = collect($details->checklists ?? [])
                    ->sum(fn (mixed $c): int => is_array($c) && is_array($c['items'] ?? null) ? count($c['items']) : 0);
            }
            if ($details->isDirty('card_attachments')) {
                $details->has_link_attachment = collect($details->card_attachments ?? [])
                    ->contains(fn (mixed $a): bool => is_array($a) && ($a['status'] ?? null) === 'link' && is_string($a['url'] ?? null));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'checklists' => 'array',
            'comments' => 'array',
            'card_attachments' => 'array',
            'checklist_items' => 'integer',
            'has_link_attachment' => 'boolean',
            'card_activity_at' => 'datetime',
            'fetched_at' => 'datetime',
            'fetch_failures' => 'integer',
            'retry_after' => 'datetime',
            'card_gone_at' => 'datetime',
        ];
    }
}
