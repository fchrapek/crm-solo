<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project, or one Trello list of it, kept off the full task list (Zadania) and the plan suggestions.
 */
final class HiddenTaskGroup extends Model
{
    protected $fillable = ['account_id', 'project_id', 'list_name', 'group_key'];

    /** One value per group, so the database refuses a second row for it; a whole project has no list. */
    public static function key(int $projectId, ?string $listName): string
    {
        return $listName === null ? (string) $projectId : "{$projectId}|{$listName}";
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
