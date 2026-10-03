<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\HumanizedText;
use App\Services\Agent\AgentWriteRecorder;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TimeEntry extends Model
{
    /** Historical: entries imported from Clockify before the integration was removed; nothing creates them now. */
    public const string SOURCE_CLOCKIFY = 'clockify';

    public const string SOURCE_TERMINAL_SESSION = 'terminal_session';

    public const string SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'account_id',
        'project_id',
        'client_id',
        'task_id',
        'source',
        'start_request_id',
        'title',
        'description',
        'start_time',
        'end_time',
        'duration_minutes',
        'billable',
        'tags',
    ];

    /** The one rounding rule for time: whole minutes, rounded up, so a timer that ran bills at least one. */
    public static function minutesBetween(CarbonInterface $start, CarbonInterface $end): int
    {
        return max(0, (int) ceil($start->diffInSeconds($end) / 60));
    }

    /**
     * Opens a running timer on a task; every start path (web, agent verbs, terminal sessions) goes through here.
     * A $requestId names one start intent: sending it again returns the timer it opened instead of a second one.
     */
    public static function startFor(Task $task, string $source = self::SOURCE_MANUAL, ?string $description = null, bool $billable = true, ?string $requestId = null): self
    {
        $task->loadMissing('project');

        $attributes = [
            'account_id' => $task->project?->account_id,
            'project_id' => $task->project_id,
            'client_id' => $task->project?->client_id,
            'task_id' => $task->id,
            'source' => $source,
            'title' => $task->name,
            'description' => $description ?: $task->name,
            'start_time' => now(),
            'end_time' => null,
            'duration_minutes' => 0,
            'billable' => $billable,
        ];

        if ($requestId === null) {
            return self::create($attributes);
        }

        return self::query()->createOrFirst(
            ['account_id' => $attributes['account_id'], 'start_request_id' => $requestId],
            $attributes,
        );
    }

    /**
     * Closes a running entry at this moment; every stop path goes through here.
     * The close only lands while the row is still running, so of two stops
     * racing the first one's end time stands. Returns whether this call stopped it.
     */
    public function stopNow(?string $description = null): bool
    {
        $end = now();
        $before = ['end_time' => $this->getRawOriginal('end_time'), 'duration_minutes' => $this->getRawOriginal('duration_minutes')];
        $stopped = self::query()
            ->whereKey($this->id)
            ->whereNull('end_time')
            ->update([
                'end_time' => $end,
                'duration_minutes' => self::minutesBetween($this->start_time, $end),
            ]) > 0;

        $this->refresh();
        if ($stopped) {
            // A guarded query update fires no model event, so the stop is recorded here.
            app(AgentWriteRecorder::class)->recordUpdate($this, $before);
        }
        if ($stopped && $description !== null && $description !== '') {
            $this->update(['description' => $description]);
        }

        return $stopped;
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected static function booted(): void
    {
        // A timer started on a closed day reopens it: the stamp means nothing ran after the close.
        self::created(function (TimeEntry $entry): void {
            if ($entry->end_time === null && $entry->account_id !== null) {
                $planner = app(\App\Services\Day\DayPlanner::class);
                $planner->reopen((int) $entry->account_id, $planner->today());
            }
        });
    }

    protected function casts(): array
    {
        return [
            'title' => HumanizedText::class,
            'description' => HumanizedText::class,
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'billable' => 'boolean',
            'tags' => 'array',
        ];
    }
}
