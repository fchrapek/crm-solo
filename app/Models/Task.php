<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\HumanizedText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

final class Task extends Model
{
    public const string AGENT_LANE_BACKLOG = 'backlog';

    public const string AGENT_LANE_IN_PROGRESS = 'in_progress';

    public const string AGENT_LANE_IN_REVIEW = 'in_review';

    public const string AGENT_LANE_DONE = 'done';

    public const array AGENT_LANES = [
        self::AGENT_LANE_BACKLOG,
        self::AGENT_LANE_IN_PROGRESS,
        self::AGENT_LANE_IN_REVIEW,
        self::AGENT_LANE_DONE,
    ];

    public const string SOURCE_MANUAL = 'manual';

    public const string SOURCE_EMAIL = 'email';

    public const string SOURCE_TRELLO = 'trello';

    public const string TYPE_GENERAL = 'general';

    public const string TYPE_FEATURE = 'feature';

    public const string TYPE_BUG = 'bug';

    public const array TYPES = [
        self::TYPE_GENERAL,
        self::TYPE_FEATURE,
        self::TYPE_BUG,
    ];

    public const string CLI_CLAUDE = 'claude';

    public const string CLI_CODEX = 'codex';

    public const array CLIS = [
        self::CLI_CLAUDE,
        self::CLI_CODEX,
    ];

    public const string SESSION_MODE_WORKTREE = 'worktree';

    public const string SESSION_MODE_IN_REPO = 'in_repo';

    public const array SESSION_MODES = [
        self::SESSION_MODE_WORKTREE,
        self::SESSION_MODE_IN_REPO,
    ];

    protected $fillable = [
        'project_id',
        'trello_card_id',
        'trello_list_id',
        'name',
        'description',
        'list_name',
        'position',
        'due_date',
        'labels',
        'trello_url',
        'is_completed',
        'source',
        'type',
        'is_reviewed',
        'is_reportable',
        'review_action',
        'review_changes',
        'reviewed_at',
        'review_note',
        'rejected_at',
        'priority',
        'ai_priority',
        'ai_priority_reasoning',
        'recurrence_period_days',
        'parent_task_id',
        'agent_lane',
        'cli',
        'session_port',
        'session_pid',
        'session_token',
        'session_attention_at',
        'session_mode',
        'archived_at',
        'issue_url',
    ];

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->whereHas('project', fn ($q) => $q->where('account_id', Auth::user()->account_id))
            ->where($field ?? 'id', $value)
            ->firstOrFail();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    /**
     * Active (non-archived) children.
     */
    public function childTasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id')
            ->whereNull('archived_at')
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * Every child regardless of archive state. Used by the cascade hook in
     * booted() so deleting a parent nulls archived children too.
     */
    public function allChildTasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class)->orderBy('id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TaskSession::class)->orderBy('started_at', 'desc');
    }

    public function previews(): HasMany
    {
        return $this->hasMany(TaskPreview::class)->orderBy('started_at', 'desc');
    }

    /**
     * Currently-running preview for this task, if any. At most one row per
     * task has stopped_at IS NULL — enforced in ProjectPreviewLauncher.
     */
    public function runningPreview(): ?TaskPreview
    {
        return $this->previews()->whereNull('stopped_at')->latest('started_at')->first();
    }

    /**
     * Tasks visible on an agent kanban — anything with a CLI configured that
     * isn't archived. Drag-from-regular-kanban tasks (cli null) are excluded.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOnAgentBoard(Builder $query): void
    {
        $query->whereNotNull('cli')->whereNull('archived_at');
    }

    public function sessionBranchName(): string
    {
        return "session/task-{$this->id}";
    }

    public function sessionWorktreePath(string $repoLocalPath): string
    {
        return mb_rtrim($repoLocalPath, '/').'/.worktrees/task-'.$this->id;
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param Builder<self> $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Complete the task through the canonical path: Done list, completed
     * flag, agent lane mirrored, and — when this crossed the not-done → done
     * boundary — the recurring successor spawned. Used by the crm:task-done
     * agent verb; the controllers keep their own field-level updates but
     * share spawnRecurringInstance().
     */
    public function markDone(): void
    {
        $wasCompleted = (bool) $this->is_completed;

        $update = ['list_name' => 'Done', 'is_completed' => true];
        if ($this->agent_lane !== null) {
            $update['agent_lane'] = self::AGENT_LANE_DONE;
        }
        $this->update($update);

        if (! $wasCompleted) {
            $this->spawnRecurringInstance();
        }
    }

    /**
     * The newest open task spawned from this one — what a caller reports back
     * after completing a recurring task.
     */
    public function latestOpenSuccessor(): ?self
    {
        return self::query()
            ->where('parent_task_id', $this->id)
            ->where('is_completed', false)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * If this task has a recurrence period, create the next instance dated
     * `period_days` after today. Carries over the CLI choice so the new task
     * is immediately ready to launch on its due date.
     */
    public function spawnRecurringInstance(): void
    {
        if ($this->recurrence_period_days === null || $this->recurrence_period_days <= 0) {
            return;
        }

        self::create([
            'project_id' => $this->project_id,
            'name' => $this->name,
            'description' => $this->description,
            'list_name' => 'To-Do',
            'due_date' => now()->addDays($this->recurrence_period_days)->startOfDay(),
            'priority' => $this->priority,
            'source' => 'manual',
            'is_reviewed' => true,
            'is_completed' => false,
            'recurrence_period_days' => $this->recurrence_period_days,
            'parent_task_id' => $this->id,
            'cli' => $this->cli,
        ]);
    }

    /**
     * Cascade hook — when a parent is deleted, null out children's
     * parent_task_id so we never leave dangling references. Mirrors the DB
     * FK's ON DELETE SET NULL but works portably across drivers (SQLite tests
     * + production MariaDB) and for both Eloquent ->delete() and model events
     * triggered by relations.
     */
    protected static function booted(): void
    {
        self::deleting(function (Task $task): void {
            self::query()
                ->where('parent_task_id', $task->id)
                ->update(['parent_task_id' => null]);

            // Attachment files live outside the DB — without this, deleting a
            // task (or its project, which deletes tasks through Eloquent)
            // leaves orphaned uploads on disk forever.
            Storage::disk('local')->deleteDirectory('task-attachments/'.$task->id);
        });
    }

    protected function casts(): array
    {
        return [
            'description' => HumanizedText::class,
            'labels' => 'array',
            'review_changes' => 'array',
            'due_date' => 'datetime',
            'is_completed' => 'boolean',
            'is_reviewed' => 'boolean',
            'is_reportable' => 'boolean',
            'rejected_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'archived_at' => 'datetime',
            'session_attention_at' => 'datetime',
        ];
    }
}
