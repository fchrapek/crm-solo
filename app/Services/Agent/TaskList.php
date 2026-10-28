<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The task list for `crm tasks` and MCP `tasks`, inside one account: open
 * tasks by default (Task::scopeOpen), filtered by client, project, name and
 * due day, each item in TaskRecord::listItem's shape.
 */
final class TaskList
{
    public const array STATUSES = ['open', 'done', 'all'];

    private const int DEFAULT_LIMIT = 50;

    private const int MAX_LIMIT = 200;

    public function __construct(private readonly CrmEntityResolver $resolver) {}

    /**
     * `due_before` keeps tasks due strictly before that local day, read the
     * way Task::dueDay() reads a CRM date and a card's instant.
     *
     * @param  array{client?: ?string, project?: ?string, status?: ?string, search?: ?string, due_before?: ?string, limit?: mixed}  $filters
     * @return array<string, mixed>
     *
     * @throws AgentRefusal|ReferenceException
     */
    public function list(int $accountId, array $filters): array
    {
        $status = mb_strtolower(mb_trim((string) ($filters['status'] ?? ''))) ?: 'open';
        if (! in_array($status, self::STATUSES, true)) {
            throw AgentRefusal::invalid("Unknown status \"{$status}\".", ['valid_statuses' => self::STATUSES]);
        }
        $limit = $this->limit($filters['limit'] ?? null);
        $client = $this->filled($filters['client'] ?? null) ? $this->resolver->client((string) $filters['client'], $accountId) : null;
        $project = $this->filled($filters['project'] ?? null) ? $this->resolver->project((string) $filters['project'], $accountId) : null;
        $search = mb_trim((string) ($filters['search'] ?? ''));
        $dueBefore = $this->filled($filters['due_before'] ?? null) ? AgentDates::day((string) $filters['due_before'], 'due_before') : null;

        $query = Task::query()
            ->with('project.client:id,name')
            ->whereHas('project', fn (Builder $q) => $q->where('account_id', $accountId))
            ->when($client !== null, fn (Builder $q) => $q->whereHas('project', fn (Builder $p) => $p->where('client_id', $client->id)))
            ->when($project !== null, fn (Builder $q) => $q->where('tasks.project_id', $project->id))
            ->when($search !== '', fn (Builder $q) => $q->where('tasks.name', 'like', '%'.addcslashes($search, '\\%_').'%'))
            ->when($status === 'open', fn (Builder $q) => $q->open())
            ->when($status === 'done', fn (Builder $q) => $q->where(fn (Builder $done) => $done->whereNotNull('tasks.finished_at')->orWhere('tasks.is_completed', true)));

        $status === 'done'
            ? $query->orderByRaw('COALESCE(tasks.finished_at, tasks.updated_at) DESC')
            : $query->orderByRaw('tasks.due_date IS NULL')->orderBy('tasks.due_date');
        $query->orderByDesc('tasks.updated_at')->orderByDesc('tasks.id');

        if ($dueBefore !== null) {
            $day = $dueBefore->toDateString();
            // The bound only narrows the query: a CRM date is stored as its midnight, a card's due as a UTC instant.
            $bound = max(CarbonImmutable::parse($day, (string) config('app.timezone')), $dueBefore->setTimezone((string) config('app.timezone')));
            $tasks = $query->whereNotNull('tasks.due_date')->where('tasks.due_date', '<', $bound)->get()
                ->filter(fn (Task $task): bool => $task->dueDay() !== null && $task->dueDay() < $day)
                ->values();
            $total = $tasks->count();
            $tasks = $tasks->take($limit);
        } else {
            $total = (clone $query)->count();
            $tasks = $query->limit($limit)->get();
        }

        return [
            'status' => $status,
            'total' => $total,
            'tasks' => $tasks->map(fn (Task $task): array => TaskRecord::listItem($task))->values()->all(),
        ];
    }

    private function limit(mixed $limit): int
    {
        if ($limit === null || $limit === '') {
            return self::DEFAULT_LIMIT;
        }
        if (! is_numeric($limit) || (int) $limit < 1) {
            throw AgentRefusal::invalid('limit takes a whole number, 1 or more.');
        }

        return min((int) $limit, self::MAX_LIMIT);
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && mb_trim($value) !== '';
    }
}
