<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Actions\Clockify\PushTimeEntryToClockify;
use App\Models\TimeEntry;
use Carbon\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Manual time logging shared by the CLI and MCP — mirrors
 * TimeEntriesController::store() so every transport produces identical rows
 * (account-scoped, closed entry with consistent end_time + duration, optional
 * best-effort Clockify push).
 */
final class TimeLogger
{
    public function __construct(
        private readonly CrmEntityResolver $resolver,
    ) {}

    /**
     * Exactly one of $task / $client / $project targets the entry.
     *
     * @return array<string, mixed>
     */
    public function log(
        int $minutes,
        ?string $task = null,
        ?string $client = null,
        ?string $project = null,
        ?string $description = null,
        ?string $title = null,
        ?string $end = null,
        bool $billable = true,
        bool $push = true,
        ?int $accountId = null,
    ): array {
        if ($minutes <= 0) {
            throw new InvalidArgumentException('minutes must be a positive integer.');
        }

        $targets = array_filter([
            'task' => $task,
            'client' => $client,
            'project' => $project,
        ], fn ($value): bool => $value !== null && $value !== '');

        if (count($targets) !== 1) {
            throw new InvalidArgumentException('Pass exactly one target: task, client, or project.');
        }

        [$accId, $projectId, $clientId, $taskId, $descDefault, $label] = $this->resolveTarget($targets, $accountId);

        $displayTz = (string) config('app.display_timezone', config('app.timezone'));
        $storageTz = (string) config('app.timezone');

        try {
            // A bare "15:30" means 15:30 *local*; an explicit offset in the string
            // wins. Convert to storage tz before saving — Eloquent formats a Carbon
            // in its own zone, so an unconverted instance would store wall-clock.
            $endAt = $end !== null && $end !== ''
                ? Carbon::parse($end, $displayTz)->setTimezone($storageTz)
                : Carbon::now($storageTz);
        } catch (Throwable) {
            throw new InvalidArgumentException('Could not parse the end time. Use a datetime like "2026-07-14 15:30".');
        }

        $startAt = (clone $endAt)->subMinutes($minutes);

        $entry = TimeEntry::create([
            'account_id' => $accId,
            'project_id' => $projectId,
            'client_id' => $clientId,
            'task_id' => $taskId,
            'source' => TimeEntry::SOURCE_MANUAL,
            'title' => $title,
            'description' => $description ?? $descDefault,
            'start_time' => $startAt,
            'end_time' => $endAt,
            'duration_minutes' => $minutes,
            'billable' => $billable,
        ]);

        if ($push) {
            // Best-effort — no-ops if Clockify isn't configured (mirrors the controller).
            (new PushTimeEntryToClockify)($entry->load('project.client'));
        }

        $startLocal = (clone $startAt)->setTimezone($displayTz);
        $endLocal = (clone $endAt)->setTimezone($displayTz);

        return [
            'entry_id' => $entry->id,
            'minutes' => $minutes,
            'hours' => round($minutes / 60, 2),
            'target' => $label,
            'start_local' => $startLocal->format('Y-m-d H:i'),
            'end_local' => $endLocal->format('Y-m-d H:i'),
            'timezone' => $endLocal->format('T'),
            'billable' => $entry->billable,
            'pushed_to_clockify' => $push,
        ];
    }

    /**
     * @param  array<string, mixed>  $targets
     * @return array{0:int,1:int,2:?int,3:?int,4:string,5:string}
     */
    private function resolveTarget(array $targets, ?int $accountId): array
    {
        if (isset($targets['task'])) {
            $task = $this->resolver->task((string) $targets['task'], $accountId);

            return [$task->project->account_id, $task->project_id, $task->project->client_id, $task->id, $task->name, "task #{$task->id} \"{$task->name}\""];
        }

        if (isset($targets['project'])) {
            $project = $this->resolver->project((string) $targets['project'], $accountId);

            return [$project->account_id, $project->id, $project->client_id, null, 'Ad-hoc work', "project #{$project->id} \"{$project->name}\""];
        }

        $client = $this->resolver->client((string) $targets['client'], $accountId);
        $project = $client->projects()->where('name', 'General')->first() ?? $client->projects()->first();

        if ($project === null) {
            throw new InvalidArgumentException("Client #{$client->id} \"{$client->name}\" has no project to log against.");
        }

        return [$client->account_id, $project->id, $client->id, null, 'Ad-hoc work', "client #{$client->id} \"{$client->name}\" ({$project->name})"];
    }
}
