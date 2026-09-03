<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Project extends Model
{
    protected $fillable = [
        'account_id',
        'client_id',
        'trello_board_id',
        'clockify_project_id',
        'name',
        'description',
        'trello_url',
        'settings',
        'is_inbox',
        'include_in_month_close',
        'archived_at',
        'backup_path',
        'preview_command',
        'preview_working_dir',
        'preview_url',
    ];

    /**
     * Get (or lazily create) the per-account Inbox project — used as the default
     * destination for email-derived tasks that have no client/contact match.
     */
    public static function accountInbox(int $accountId): self
    {
        return self::firstOrCreate(
            ['account_id' => $accountId, 'is_inbox' => true],
            [
                'client_id' => null,
                'name' => 'Inbox',
                'description' => 'Auto-collected tasks from emails awaiting routing.',
            ],
        );
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    public function previews(): HasMany
    {
        return $this->hasMany(TaskPreview::class);
    }

    /**
     * Currently-running preview on this project, if any. At most one row per
     * project has stopped_at IS NULL — the per-project mutex on TaskPreviewsController.
     */
    public function runningPreview(): ?TaskPreview
    {
        return $this->previews()->whereNull('stopped_at')->latest('started_at')->first();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Default scope for anything a human browses. Archived projects are kept for
     * their task history, not for looking at.
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_inbox' => 'boolean',
            'include_in_month_close' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }
}
