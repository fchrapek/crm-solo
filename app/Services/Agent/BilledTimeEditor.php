<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Account;
use App\Models\Client;
use App\Models\Task;
use App\Models\TaskBilledMonth;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Clients\BilledTime;
use App\Services\Clients\BilledTimeResult;
use App\Services\Clients\HoursBank;
use App\Support\LegalName;
use App\Support\LocalCalendar;

/** The agent side of BilledTime: both values of a client's month or of one task, and the task figures, inside the identity's account. */
final readonly class BilledTimeEditor
{
    public function __construct(
        private BilledTime $billed,
        private CrmEntityResolver $resolver,
        private MonthCloseReader $months,
        private HoursBank $bank,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws AgentRefusal
     * @throws ReferenceException
     */
    public function read(Account $account, string $clientNeedle, ?string $month): array
    {
        [$client, $period] = $this->target($account, $clientNeedle, $month);

        return $this->figures($client, $period);
    }

    /**
     * A task's two values for a month (default: the month it was last worked in) and the month they add up to.
     *
     * @return array<string, mixed>
     *
     * @throws AgentRefusal
     * @throws ReferenceException
     */
    public function taskRead(Account $account, string $taskNeedle, ?string $month): array
    {
        [$task, $client, $period] = $this->taskTarget($account, $taskNeedle, $month);

        return $this->taskFigures($task, $client, $period);
    }

    /**
     * Sets a task's billed hours for a month, or clears them so it bills its real time; exactly one of $hours and
     * $clear, $note null keeps the stored note.
     *
     * @return array{outcome: string, message: string, recalculate: ?string, task: array<string, mixed>}
     *
     * @throws AgentRefusal
     * @throws ReferenceException
     */
    public function taskSet(Account $account, string $taskNeedle, ?string $month, ?float $hours, bool $clear, ?string $note): array
    {
        if (($hours !== null) === $clear) {
            throw AgentRefusal::invalid('Pass exactly one of --hours and --clear.');
        }
        $missing = app(AgentCallContext::class)->missing([AgentAbilities::BILLING]);
        if ($missing !== []) {
            throw new AgentRefusal('missing_ability', 'Billing a task needs the '.AgentAbilities::BILLING.' ability, which this token was not issued with.');
        }
        [$task, $client, $period] = $this->taskTarget($account, $taskNeedle, $month);

        $result = $this->billed->setTask($task, $period, $clear ? null : $hours, $note, $this->user());
        $this->refuseOn($result, ['task_id' => $task->id, 'client_id' => $client->id, 'period' => $period]);

        return [
            'outcome' => $result->outcome,
            'message' => $result->outcome === BilledTimeResult::UNCHANGED ? 'Nothing changed.' : $result->message,
            'recalculate' => $result->recalculate,
            'task' => $this->taskFigures($task->fresh(), $client->fresh(), $period),
        ];
    }

    /** @return array<string, mixed> */
    private function figures(Client $client, string $period): array
    {
        $figures = $this->billed->figures($client, $period);
        $figures['tasks'] = array_map(fn (array $row): array => [...$row, 'untrusted' => ['task', 'project']], $figures['tasks']);

        return $figures;
    }

    /** @return array<string, mixed> */
    private function taskFigures(Task $task, Client $client, string $period): array
    {
        $month = $this->billed->figures($client, $period);
        $row = collect($month['tasks'])->firstWhere('task_id', $task->id);
        [$from, $to] = LocalCalendar::monthRange($period);
        $real = (int) TimeEntry::query()->where('task_id', $task->id)->where('client_id', $client->id)->where('start_time', '>=', $from)->where('start_time', '<', $to)->sum('duration_minutes');
        $stored = TaskBilledMonth::query()->where('task_id', $task->id)->where('client_id', $client->id)->orderBy('period')->get(['period', 'billed_minutes', 'note']);
        $refusal = match (true) {
            $month['refusal_code'] !== null => ['code' => $month['refusal_code'], 'message' => $month['refusal']],
            ! $task->is_reportable => ['code' => BilledTime::CODE_NOT_REPORTABLE, 'message' => TaskBillingNotes::forTask($task)['warnings'][0]],
            default => null,
        };

        return [
            'task_id' => $task->id,
            'task' => (string) $task->name,
            'client_id' => $client->id,
            'client' => LegalName::short($client->name),
            'reportable' => (bool) $task->is_reportable,
            'period' => $period,
            'real' => BilledTime::hours($real),
            'stated' => $row['stated'] ?? null,
            'billed' => $row['billed'] ?? ($task->is_reportable ? 0.0 : null),
            'note' => $stored->firstWhere('period', $period)?->note,
            'month' => [
                'real' => $month['real'],
                'billed' => $month['billed'],
                'has_retainer' => $month['has_retainer'],
                'closing' => $month['has_retainer'] ? $month['closing'] : null,
                'report' => $month['report'],
            ],
            'other_months' => $stored->where('period', '!=', $period)->map(fn (TaskBilledMonth $r): array => ['period' => $r->period, 'stated' => BilledTime::hours($r->billed_minutes)])->values()->all(),
            'refusal' => $refusal['message'] ?? null,
            'refusal_code' => $refusal['code'] ?? null,
            'untrusted' => ['task'],
        ];
    }

    /** @return array{Task, Client, string} */
    private function taskTarget(Account $account, string $taskNeedle, ?string $month): array
    {
        $this->months->assertBilling($account);
        $task = $this->resolver->task($taskNeedle, $account->id);
        $client = $task->project?->client;
        if ($client === null) {
            throw AgentRefusal::invalid("Task {$task->id} belongs to no client, so it has no billed time.");
        }
        $period = $month ?? BilledTime::taskPeriod($task);
        if (! LocalCalendar::isMonth($period)) {
            throw AgentRefusal::invalid('The month must be YYYY-MM.');
        }

        return [$task, $client, $period];
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws AgentRefusal
     */
    private function refuseOn(BilledTimeResult $result, array $context): void
    {
        if ($result->isRefused()) {
            throw new AgentRefusal((string) $result->code, $result->message, $context);
        }
    }

    /** @return array{Client, string} */
    private function target(Account $account, string $clientNeedle, ?string $month): array
    {
        $this->months->assertBilling($account);
        $client = $this->resolver->client($clientNeedle, $account->id);
        // Without a month: the oldest one still open, the month a close is owed for.
        $period = $month ?? $this->bank->activeMonth($client);
        if (! LocalCalendar::isMonth($period)) {
            throw AgentRefusal::invalid('The month must be YYYY-MM.');
        }

        return [$client, $period];
    }

    private function user(): ?User
    {
        return app(AgentCallContext::class)->identity()->user;
    }
}
