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
 * anything else is a name fragment, always inside the acting account (a record
 * in another account does not exist as far as the caller can tell). Zero matches and several matches both throw
 * a ReferenceException carrying the candidates, so the CLI and MCP disagree only
 * in how they render it.
 */
final class CrmEntityResolver
{
    private const int CANDIDATE_LIMIT = 6;

    public function client(string $needle, int $accountId): Client
    {
        $query = Client::query()->where('account_id', $accountId);

        if (ctype_digit($needle)) {
            return $query->find((int) $needle) ?? throw new ReferenceNotFoundException('client', $needle);
        }

        return $this->single(
            'client',
            $needle,
            $query->where('name', 'like', "%{$needle}%")->orderBy('name')->orderBy('id'),
            fn (Client $client): ?string => null,
        );
    }

    public function project(string $needle, int $accountId): Project
    {
        $query = Project::query()->where('account_id', $accountId);

        if (ctype_digit($needle)) {
            return $query->find((int) $needle) ?? throw new ReferenceNotFoundException('project', $needle);
        }

        return $this->single(
            'project',
            $needle,
            $query->with('client:id,name')->where('name', 'like', "%{$needle}%")
                ->orderByRaw('archived_at IS NOT NULL')->orderBy('name')->orderBy('id'),
            fn (Project $project): ?string => $project->client?->name,
        );
    }

    /**
     * An explicit id always resolves. A name fragment matches open tasks only
     * when $openOnly is set (starting work); otherwise it matches every task
     * (logging or correcting past time), open ones listed first. Within each
     * group the most recently touched comes first.
     */
    public function task(string $needle, int $accountId, bool $openOnly = false): Task
    {
        $query = Task::query()
            ->with('project.client')
            ->whereHas('project', fn (Builder $inner) => $inner->where('account_id', $accountId));

        if (ctype_digit($needle)) {
            return $query->find((int) $needle) ?? throw new ReferenceNotFoundException('task', $needle);
        }

        $named = $query->where('tasks.name', 'like', "%{$needle}%");
        $recent = fn (Builder $q): Builder => $q->orderByDesc('tasks.updated_at')->orderByDesc('tasks.id');
        $open = $recent((clone $named)->open());
        $closed = $recent((clone $named)->whereNotIn('tasks.id', (clone $named)->open()->select('tasks.id')));
        $context = fn (Task $task): ?string => collect([
            $task->project?->name,
            $task->isDone() ? 'finished' : null,
            $task->isArchived() ? 'archived' : null,
        ])->filter()->implode(', ') ?: null;

        if ($openOnly) {
            try {
                return $this->single('task', $needle, $open, $context);
            } catch (ReferenceNotFoundException $e) {
                $past = (clone $closed)->limit(self::CANDIDATE_LIMIT)->get();
                if ($past->isEmpty()) {
                    throw $e;
                }

                throw new ReferenceNotFoundException(
                    'open task',
                    $needle,
                    'Only finished or archived tasks match; reopen the task to start work on it, or use time:log for past time.',
                    $this->candidates($past, $context),
                );
            }
        }

        return $this->single('task', $needle, [$open, $closed], $context);
    }

    /**
     * Running timers only. A null needle means "the one that is open" and is
     * ambiguous the moment a second timer runs — overlapping timers are legal
     * here, so this reports rather than guesses.
     */
    public function openTimeEntry(int $accountId, ?string $needle = null): TimeEntry
    {
        $open = TimeEntry::query()
            ->whereNull('end_time')
            ->orderByDesc('start_time')
            ->orderByDesc('id')
            ->where('account_id', $accountId)
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
     * Resolves a fragment against one ordered query, or several queried in
     * turn (open tasks before finished ones). The ambiguity error lists the
     * first CANDIDATE_LIMIT matches and says how many matched in all.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>|list<Builder<TModel>>  $queries
     * @param  callable(TModel): ?string  $context
     * @return TModel
     */
    private function single(string $entity, string $needle, Builder|array $queries, callable $context)
    {
        // The decision reads only the fetched rows (one more than the list shows, so a
        // second match is always seen); the counts only feed the "of N" in the message.
        $matches = collect();
        $counted = 0;
        foreach (is_array($queries) ? $queries : [$queries] as $query) {
            $counted += (clone $query)->count();
            $room = self::CANDIDATE_LIMIT + 1 - $matches->count();
            if ($room > 0) {
                $matches = $matches->concat((clone $query)->limit($room)->get())->unique('id')->values();
            }
        }

        if ($matches->isEmpty()) {
            throw new ReferenceNotFoundException($entity, $needle);
        }

        if ($matches->count() === 1) {
            return $matches->first();
        }

        throw new AmbiguousReferenceException(
            $entity,
            $needle,
            $this->candidates($matches->take(self::CANDIDATE_LIMIT), $context),
            max($counted, $matches->count()),
        );
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TModel>  $models
     * @param  callable(TModel): ?string  $context
     * @return list<array{id: int, name: string, context: string|null}>
     */
    private function candidates(Collection $models, callable $context): array
    {
        return $models->map(fn ($model): array => [
            'id' => (int) $model->id,
            'name' => (string) $model->name,
            'context' => $context($model),
        ])->values()->all();
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
