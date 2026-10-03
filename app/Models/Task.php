<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\TaskDescription;
use App\Services\Integrations\Trello\TrelloListMapper;
use App\Support\LocalCalendar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\UniqueConstraintViolationException;
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

    /** The terminal session's bearer credential for the session-events endpoint. */
    protected $hidden = [
        'session_token',
    ];

    protected $fillable = [
        'project_id',
        'trello_card_id',
        'trello_list_id',
        'trello_due_complete',
        'trello_activity_at',
        'trello_move_pending_at',
        'name',
        'description',
        'list_name',
        'position',
        'due_date',
        'labels',
        'trello_url',
        'is_completed',
        'finished_at',
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
        'recurrence_predecessor_id',
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

    /** The CRM's own brief on the task: where, done when, constraints, notes. */
    public function brief(): HasOne
    {
        return $this->hasOne(TaskBrief::class);
    }

    /** The cached checklists, comments and attachment list of a Trello card. */
    public function cardDetails(): HasOne
    {
        return $this->hasOne(TaskCardDetails::class);
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
     * The one definition of an open task: not finished by the owner, not
     * completed on its board, not archived, and not left on the Done list
     * (a card on Done is finished even when nobody ticked it). Its project
     * must still be live: not archived, and its client (if any) not deleted.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('finished_at'))
            ->where($query->qualifyColumn('is_completed'), false)
            ->whereNull($query->qualifyColumn('archived_at'))
            ->where(fn (Builder $q) => $q->whereNull($query->qualifyColumn('list_name'))
                ->orWhere($query->qualifyColumn('list_name'), '!=', TrelloListMapper::LANE_DONE))
            ->whereHas('project', fn (Builder $project) => $project
                ->whereNull($project->qualifyColumn('archived_at'))
                ->where(fn (Builder $q) => $q->whereNull($project->qualifyColumn('client_id'))->orWhereHas('client')));
    }

    /** A synced card: Trello owns its title, description, list and completion. */
    public function hasTrelloCard(): bool
    {
        return $this->trello_card_id !== null;
    }

    /** Done for the owner: ticked in the CRM, or completed on its board. */
    public function isDone(): bool
    {
        return $this->finished_at !== null || (bool) $this->is_completed;
    }

    /**
     * The calendar day the task is due. A CRM due date is a date, stored as its
     * midnight, so it is read as written; a Trello card's due is an instant,
     * read in the display timezone.
     */
    public function dueDay(): ?string
    {
        if ($this->due_date === null) {
            return null;
        }

        return $this->hasTrelloCard()
            ? $this->due_date->copy()->setTimezone(LocalCalendar::timezone())->toDateString()
            : $this->due_date->toDateString();
    }

    /**
     * The row's own half of scopeOpen: not finished, not completed, not
     * archived and not on the Done list. (The scope also checks the project.)
     */
    public function isOpen(): bool
    {
        return ! $this->isDone() && ! $this->isArchived() && $this->list_name !== TrelloListMapper::LANE_DONE;
    }

    /** Overdue once its due day has ended in the display timezone, and only while it is still open. */
    public function isOverdue(): bool
    {
        $day = $this->dueDay();

        return $day !== null && $day < LocalCalendar::today()->toDateString() && $this->isOpen();
    }

    /**
     * Whether a CRM untick can make this task open again. A card completed
     * on its board stays done whatever the CRM clears, so only Trello reopens it.
     */
    public function canUnfinish(): bool
    {
        return $this->hasTrelloCard()
            ? $this->finished_at !== null && ! $this->is_completed
            : $this->isDone();
    }

    /**
     * The two facts behind "done", as the agent verbs report them: the
     * owner's finish, and for a Trello card where the card itself sits.
     *
     * @return array{finished_at: ?string, is_completed: bool, card_lane: ?string}
     */
    public function completionState(): array
    {
        return [
            'finished_at' => $this->finished_at?->toIso8601String(),
            'is_completed' => (bool) $this->is_completed,
            'card_lane' => $this->hasTrelloCard() ? $this->list_name : null,
        ];
    }

    /**
     * The newest open task spawned from this one — what a caller reports back
     * after completing a recurring task.
     */
    public function latestOpenSuccessor(): ?self
    {
        return self::query()
            ->where('recurrence_predecessor_id', $this->id)
            ->where('is_completed', false)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * If this task has a recurrence period, create the next instance dated
     * `period_days` after today. Carries over the CLI choice so the new task
     * is immediately ready to launch on its due date, and whether it shows in
     * client reports. One next instance per task: a repeated finish, or an
     * untick and a tick again, keeps the one it already made, even once that
     * one is itself done.
     */
    public function spawnRecurringInstance(): void
    {
        if ($this->recurrence_period_days === null || $this->recurrence_period_days <= 0) {
            return;
        }

        // Open or already done, the next instance exists: a re-finish never adds another,
        // whatever the interval is now. The unique column settles a race.
        if (self::query()->where('recurrence_predecessor_id', $this->id)->exists()) {
            return;
        }

        try {
            self::create([
                'project_id' => $this->project_id,
                'name' => $this->name,
                'description' => $this->description,
                'list_name' => 'To-Do',
                'due_date' => LocalCalendar::today()->addDays($this->recurrence_period_days)->toDateString(),
                'priority' => $this->priority,
                'source' => 'manual',
                'is_reviewed' => true,
                'is_completed' => false,
                'recurrence_period_days' => $this->recurrence_period_days,
                'is_reportable' => (bool) $this->is_reportable,
                'parent_task_id' => $this->id,
                'recurrence_predecessor_id' => $this->id,
                'cli' => $this->cli,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another finish of this task spawned it first.
        }
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
            self::query()
                ->where('recurrence_predecessor_id', $task->id)
                ->update(['recurrence_predecessor_id' => null]);

            // Logged time outlives the task: it keeps its project and client, so it is still billed.
            TimeEntry::query()
                ->where('task_id', $task->id)
                ->update(['task_id' => null]);

            // Attachment files live outside the DB — without this, deleting a
            // task (or its project, which deletes tasks through Eloquent)
            // leaves orphaned uploads on disk forever.
            Storage::disk('local')->deleteDirectory('task-attachments/'.$task->id);
        });
    }

    protected function casts(): array
    {
        return [
            'description' => TaskDescription::class,
            'labels' => 'array',
            'review_changes' => 'array',
            'due_date' => 'datetime',
            'is_completed' => 'boolean',
            'trello_due_complete' => 'boolean',
            'trello_activity_at' => 'datetime',
            'trello_move_pending_at' => 'datetime',
            'finished_at' => 'datetime',
            'is_reviewed' => 'boolean',
            'is_reportable' => 'boolean',
            'rejected_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'archived_at' => 'datetime',
            'session_attention_at' => 'datetime',
        ];
    }
}
