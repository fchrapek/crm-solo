<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One reference resolver for every agent transport: a numeric needle is an id,
 * anything else is a name fragment. Zero matches and several matches both throw
 * a ReferenceException carrying the candidates, so the CLI and MCP disagree only
 * in how they render it.
 */
final class CrmEntityResolver
{
    private const int CANDIDATE_LIMIT = 6;

    public function client(string $needle, ?int $accountId = null): Client
    {
        $query = Client::query()->when($accountId !== null, fn (Builder $q) => $q->where('account_id', $accountId));

        if (ctype_digit($needle)) {
            return $query->find((int) $needle) ?? throw new ReferenceNotFoundException('client', $needle);
        }

        return $this->single(
            'client',
            $needle,
            $query->where('name', 'like', "%{$needle}%")->limit(self::CANDIDATE_LIMIT)->get(),
            fn (Client $client): ?string => null,
        );
    }

    public function project(string $needle, ?int $accountId = null): Project
    {
        $query = Project::query()->when($accountId !== null, fn (Builder $q) => $q->where('account_id', $accountId));

        if (ctype_digit($needle)) {
            return $query->find((int) $needle) ?? throw new ReferenceNotFoundException('project', $needle);
        }

        return $this->single(
            'project',
            $needle,
            $query->with('client:id,name')->where('name', 'like', "%{$needle}%")->limit(self::CANDIDATE_LIMIT)->get(),
            fn (Project $project): ?string => $project->client?->name,
        );
    }

    public function task(string $needle, ?int $accountId = null): Task
    {
        $query = Task::query()
            ->with('project.client')
            ->when($accountId !== null, fn (Builder $q) => $q->whereHas('project', fn (Builder $inner) => $inner->where('account_id', $accountId)));

        if (ctype_digit($needle)) {
            return $query->find((int) $needle) ?? throw new ReferenceNotFoundException('task', $needle);
        }

        return $this->single(
            'task',
            $needle,
            $query->where('name', 'like', "%{$needle}%")->limit(self::CANDIDATE_LIMIT)->get(),
            fn (Task $task): ?string => $task->project?->name,
        );
    }

    /**
     * Running timers only. A null needle means "the one that is open" and is
     * ambiguous the moment a second timer runs — overlapping timers are legal
     * here, so this reports rather than guesses.
     */
    public function openTimeEntry(?string $needle = null, ?int $accountId = null): TimeEntry
    {
        $open = TimeEntry::query()
            ->whereNull('end_time')
            ->when($accountId !== null, fn (Builder $q) => $q->where('account_id', $accountId))
            ->with('task:id,name', 'client:id,name')
            ->get();

        if ($open->isEmpty()) {
            throw new ReferenceNotFoundException('running timer', $needle ?? 'any');
        }

        if ($needle === null || $needle === '') {
            return $open->count() === 1
                ? $open->first()
                : throw new AmbiguousReferenceException('running timer', 'any', $this->timerCandidates($open));
        }

        if (ctype_digit($needle)) {
            return $open->firstWhere('id', (int) $needle)
                ?? throw new ReferenceNotFoundException('running timer', $needle);
        }

        $matches = $open
            ->filter(fn (TimeEntry $entry): bool => str_contains(mb_strtolower((string) $entry->task?->name), mb_strtolower($needle)))
            ->values();

        if ($matches->isEmpty()) {
            throw new ReferenceNotFoundException('running timer', $needle);
        }

        return $matches->count() === 1
            ? $matches->first()
            : throw new AmbiguousReferenceException('running timer', $needle, $this->timerCandidates($matches));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TModel>  $matches
     * @param  callable(TModel): ?string  $context
     * @return TModel
     */
    private function single(string $entity, string $needle, Collection $matches, callable $context)
    {
        if ($matches->isEmpty()) {
            throw new ReferenceNotFoundException($entity, $needle);
        }

        if ($matches->count() > 1) {
            throw new AmbiguousReferenceException($entity, $needle, $matches->map(fn ($model): array => [
                'id' => (int) $model->id,
                'name' => (string) $model->name,
                'context' => $context($model),
            ])->values()->all());
        }

        return $matches->first();
    }

    /**
     * @param  Collection<int, TimeEntry>  $entries
     * @return list<array{id: int, name: string, context: string|null}>
     */
    private function timerCandidates(Collection $entries): array
    {
        return $entries->map(fn (TimeEntry $entry): array => [
            'id' => (int) $entry->id,
            'name' => $entry->task?->name ?? 'no task',
            'context' => $entry->client?->name,
        ])->values()->all();
    }
}
