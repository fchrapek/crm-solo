<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskBilledMonth;
use App\Models\TimeEntry;
use App\Services\Clients\BilledTime;
use App\Services\Clients\ClientFinancialLock;
use App\Services\Integrations\Trello\TrelloListMapper;
use App\Services\Tasks\TaskCompletion;
use App\Services\Tasks\TaskWriter;
use App\Services\Tasks\TrelloOwnedFields;
use App\Services\Time\TimeEntryChanged;
use App\Services\Time\TimeEntryWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Task create and edit for `crm task-create` / `task-edit` and MCP
 * `task_create` / `task_edit`: references resolved inside the account,
 * input checked, then the task form's own path (TaskWriter, TaskCompletion).
 */
final class TaskEditor
{
    private const int NAME_MAX = 500;

    private const int DESCRIPTION_MAX = 50000;

    /** Values that clear a due date on edit. */
    private const array CLEAR = ['', 'none', 'clear'];

    public function __construct(
        private readonly CrmEntityResolver $resolver,
        private readonly TaskWriter $writer,
        private readonly TaskCompletion $completion,
        private readonly TimeEntryWriter $time,
    ) {}

    /**
     * @param  array{name?: ?string, project?: ?string, client?: ?string, list?: ?string, due?: ?string, description?: ?string, reportable?: ?bool}  $input
     * @return array<string, mixed>
     *
     * @throws AgentRefusal|ReferenceException
     */
    public function create(int $accountId, array $input): array
    {
        $name = $this->name($input['name'] ?? null);
        $project = $this->target($accountId, $input['project'] ?? null, $input['client'] ?? null);
        $list = $this->filled($input['list'] ?? null) ? $this->list((string) $input['list']) : null;
        $due = $this->filled($input['due'] ?? null) ? AgentDates::day((string) $input['due'], 'due')->toDateString() : null;

        $task = $this->writer->create($project, [
            'name' => $name,
            'description' => $this->description($input['description'] ?? null),
            'list_name' => $list,
            'due_date' => $due,
            'is_reportable' => isset($input['reportable']) ? (bool) $input['reportable'] : null,
        ]);

        return ['task' => TaskRecord::listItem($task->fresh(['project.client']) ?? $task)];
    }

    /**
     * Only the keys present change. `archive` true archives and false
     * unarchives; `reopen` takes a done task back to To-Do, or to `list`;
     * `move_time` brings the task's logged time onto its project, except
     * time in a settled period, which is listed as skipped. `within_client`
     * keeps the move inside the task's client: only time already on that
     * client follows, and no billed figure moves. `reportable` decides
     * whether the task's time counts in reports and the hours bank.
     *
     * @param  array{name?: ?string, description?: ?string, due?: ?string, list?: ?string, project?: ?string, reportable?: ?bool, archive?: ?bool, reopen?: ?bool, move_time?: ?bool, within_client?: ?bool}  $input
     * @return array<string, mixed>
     *
     * @throws AgentRefusal|ReferenceException
     */
    public function edit(int $accountId, string $reference, array $input): array
    {
        $task = $this->resolver->task($reference, $accountId);
        $withinClient = (bool) ($input['within_client'] ?? false);
        $changes = [];

        if (array_key_exists('name', $input) && $input['name'] !== null) {
            $changes['name'] = $this->name($input['name']);
        }
        if (array_key_exists('description', $input) && $input['description'] !== null) {
            $changes['description'] = $this->description($input['description']);
        }
        if (array_key_exists('due', $input) && $input['due'] !== null) {
            $changes['due_date'] = in_array(mb_strtolower(mb_trim($input['due'])), self::CLEAR, true)
                ? null
                : AgentDates::day($input['due'], 'due')->toDateString();
        }
        if (array_key_exists('list', $input) && $input['list'] !== null) {
            $changes['list_name'] = $this->list($input['list']);
        }
        if (array_key_exists('project', $input) && $input['project'] !== null) {
            $project = $this->resolver->project($input['project'], $accountId);
            if ($project->id !== $task->project_id) {
                if ($withinClient && (int) $project->client_id !== (int) $task->project?->client_id) {
                    throw AgentRefusal::invalid('Pick a project of the same client.', ['field' => 'project']);
                }
                $this->guardProject($project);
                if ($task->allChildTasks()->exists()) {
                    throw AgentRefusal::invalid("Task #{$task->id} has subtasks: move each subtask first, then the task.", ['field' => 'project']);
                }
                $changes['project_id'] = $project->id;
                // A parent must sit in the same project, as the task form requires.
                $changes['parent_task_id'] = null;
            }
        }
        if (array_key_exists('reportable', $input) && $input['reportable'] !== null) {
            $changes['is_reportable'] = (bool) $input['reportable'];
        }
        $archive = $input['archive'] ?? null;
        $reopen = (bool) ($input['reopen'] ?? false);
        $moveTime = (bool) ($input['move_time'] ?? false);

        if ($changes === [] && $archive === null && ! $reopen && ! $moveTime) {
            throw AgentRefusal::invalid('Nothing to change: pass name, description, due, list, project, reportable, archive, unarchive, reopen or move time.');
        }
        $toClient = isset($changes['project_id']) ? $project->client_id : $task->project?->client_id;
        if ($moveTime && ! $withinClient && BilledTime::figuresWouldMove($task, $toClient !== null ? (int) $toClient : null) && app(AgentCallContext::class)->missing([AgentAbilities::BILLING]) !== []) {
            throw new AgentRefusal('missing_ability', 'Moving this task\'s time also moves its billed figures, which needs the '.AgentAbilities::BILLING.' ability.');
        }
        if ($moveTime && ! $withinClient && $toClient !== null) {
            $to = (int) $toClient;
            $clash = TaskBilledMonth::query()->where('task_id', $task->id)->where('client_id', '!=', $to)
                ->whereIn('period', TaskBilledMonth::query()->select('period')->where('task_id', $task->id)->where('client_id', $to))->pluck('period')->unique()->values()->all();
            if ($clash !== []) {
                throw AgentRefusal::invalid("Task #{$task->id} has billed figures on both clients for ".implode(', ', $clash).': clear one with task-billed first, then move the time.', ['periods' => $clash]);
            }
        }

        try {
            $this->writer->guardCard($task, [...array_keys($changes), ...($archive !== null ? ['archived'] : [])]);
        } catch (TrelloOwnedFields $e) {
            throw new AgentRefusal(AgentRefusal::TRELLO_OWNED, $e->getMessage(), ['fields' => $e->fields, 'card_url' => $task->trello_url, 'untrusted' => ['card_url']]);
        }

        $lane = null;
        if ($reopen) {
            $lane = $changes['list_name'] ?? null;
            unset($changes['list_name']);
            if ($lane === TrelloListMapper::LANE_DONE) {
                throw AgentRefusal::invalid('reopen and list Done contradict each other.');
            }
        }

        try {
            DB::transaction(function () use ($task, $changes, $archive, $reopen, $lane): void {
                if ($changes !== []) {
                    $this->writer->update($task, $changes);
                }
                if ($archive !== null) {
                    $this->writer->setArchived($task, (bool) $archive);
                }
                if ($reopen) {
                    $this->completion->unfinish($task, $lane);
                }
            });
        } catch (ValidationException $e) {
            throw AgentRefusal::invalid((string) collect($e->errors())->flatten()->first());
        }

        try {
            [$time, $stayedBilled] = match (true) {
                $moveTime && $withinClient => $this->moveClientTime($task->fresh() ?? $task),
                $moveTime => $this->moveTimeAndFigures($task->fresh() ?? $task),
                default => [['moved' => [], 'skipped' => []], []],
            };
        } catch (TimeEntryChanged) {
            throw AgentRefusal::invalid('A time entry changed while its time was moving: run the same edit again to move the rest.');
        }

        $changed = array_keys(array_diff_key($changes, ['parent_task_id' => true]));
        if ($archive !== null) {
            $changed[] = $archive ? 'archived' : 'unarchived';
        }
        if ($reopen) {
            $changed[] = 'reopened';
        }
        if ($time['moved'] !== []) {
            $changed[] = 'time';
        }

        return [
            'task' => TaskRecord::listItem($task->fresh(['project.client']) ?? $task),
            ...$moveTime ? [
                'moved_time' => array_map(fn ($entry): int => (int) $entry->id, $time['moved']),
                'skipped_time' => array_map(fn (array $skip): array => [
                    'id' => (int) $skip['entry']->id,
                    ...array_diff_key(AgentRefusal::periodSettled($skip['settled'])->toPayload(), ['error' => true]),
                ], $time['skipped']),
                'billed_stayed' => $stayedBilled,
                ...$withinClient ? ['time_elsewhere' => $this->timeElsewhere($task->fresh() ?? $task)] : [],
            ] : [],
            'changed' => array_values(array_map(fn (string $key): string => match ($key) {
                'list_name' => 'list',
                'due_date' => 'due',
                'project_id' => 'project',
                'is_reportable' => 'reportable',
                default => $key,
            }, $changed)),
        ];
    }

    /**
     * The task's time and its billed figures move in one transaction under every client involved, so neither can settle in between.
     *
     * @return array{0: array{moved: list<mixed>, skipped: list<array<string, mixed>>}, 1: list<string>}
     */
    private function moveTimeAndFigures(Task $task): array
    {
        $to = $task->project?->client_id;
        $clients = array_values(array_unique([...BilledTime::clientsOfTask($task), ...($to !== null ? [(int) $to] : [])]));

        return app(ClientFinancialLock::class)->clients($clients, fn (): array => [$this->time->followTask($task), app(BilledTime::class)->followTask($task)]);
    }

    /**
     * A move inside one client changes no client's totals, so it holds only that client and leaves every figure alone.
     *
     * @return array{0: array{moved: list<mixed>, skipped: list<array<string, mixed>>}, 1: list<string>}
     */
    private function moveClientTime(Task $task): array
    {
        $client = $task->project?->client_id;

        return app(ClientFinancialLock::class)->clients($client !== null ? [(int) $client] : [], fn (): array => [$this->time->followTask($task, withinClient: true), []]);
    }

    /** @return list<int> the task's time entries left on another client */
    private function timeElsewhere(Task $task): array
    {
        return TimeEntry::query()->where('task_id', $task->id)
            ->where(fn ($query) => $query->whereNull('client_id')->orWhere('client_id', '!=', $task->project?->client_id))
            ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Exactly one of a project or a client; a client means its general project.
     */
    private function target(int $accountId, ?string $project, ?string $client): Project
    {
        if ($this->filled($project) === $this->filled($client)) {
            throw AgentRefusal::invalid('Pass exactly one of project or client.');
        }

        $target = $this->filled($project)
            ? $this->resolver->project((string) $project, $accountId)
            : $this->resolver->client((string) $client, $accountId)->ensureGeneralProject();
        $this->guardProject($target);

        return $target;
    }

    /** A Trello board takes a CRM task as the task form does; the sync only touches tasks that have a card. */
    private function guardProject(Project $project): void
    {
        if ($project->isArchived()) {
            throw AgentRefusal::invalid("Project #{$project->id} is archived.", ['project_id' => $project->id]);
        }
    }

    private function name(?string $name): string
    {
        $name = mb_trim((string) $name);
        if ($name === '') {
            throw AgentRefusal::invalid('Pass the task name.', ['field' => 'name']);
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw AgentRefusal::invalid('The task name is longer than '.self::NAME_MAX.' characters.', ['field' => 'name']);
        }

        return $name;
    }

    private function description(?string $description): ?string
    {
        $description = mb_trim((string) $description);
        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw AgentRefusal::invalid('The description is longer than '.self::DESCRIPTION_MAX.' characters.', ['field' => 'description']);
        }

        return $description === '' ? null : $description;
    }

    private function list(string $list): string
    {
        foreach (TaskWriter::LISTS as $canonical) {
            if (mb_strtolower(mb_trim($list)) === mb_strtolower($canonical)) {
                return $canonical;
            }
        }

        throw AgentRefusal::invalid("Unknown list \"{$list}\".", ['valid_lists' => TaskWriter::LISTS]);
    }

    private function filled(?string $value): bool
    {
        return $value !== null && mb_trim($value) !== '';
    }
}
