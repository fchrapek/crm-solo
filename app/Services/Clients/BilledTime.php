<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientBilledMonth;
use App\Models\ClientReport;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskBilledMonth;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Reports\ReportWriter;
use App\Services\Time\PeriodSettled;
use App\Support\HoursText;
use App\Support\LegalName;
use App\Support\LocalCalendar;
use Illuminate\Support\Carbon;

/**
 * Billed time beside real time, per task and month, for every client: a task bills the figure stated for it, else its
 * real time, and the month bills the sum of its tasks. Every write holds ClientFinancialLock and leaves the time
 * entries untouched.
 */
final class BilledTime
{
    public const float MAX_HOURS = 9999.0;

    public const string CODE_INVALID = 'invalid_argument';

    public const string CODE_PERIOD_SETTLED = 'period_settled';

    public const string CODE_NOT_A_DRAFT = 'not_a_draft';

    public const string CODE_REFUSED = 'refused';

    public const string CODE_NOT_REPORTABLE = 'not_reportable';

    public function __construct(
        private readonly ClientFinancialLock $lock,
        private readonly SettlementPolicy $policy,
        private readonly HoursBank $bank,
    ) {}

    public static function hours(int $minutes): float
    {
        return round($minutes / 60, 2);
    }

    public static function minutes(float $hours): int
    {
        return (int) round($hours * 60);
    }

    /**
     * The month of every client asked for, three queries whatever the count; the note is one a month carried before
     * figures were per task.
     *
     * @param  array<int, int>  $realMinutes  reportable minutes by client id
     * @return array<int, BilledMonth> keyed by client id
     */
    public static function months(array $realMinutes, string $period): array
    {
        if ($realMinutes === []) {
            return [];
        }

        $ids = array_keys($realMinutes);
        $notes = ClientBilledMonth::query()->whereIn('client_id', $ids)->where('period', $period)->pluck('note', 'client_id');
        $tasks = self::taskMonths($ids, $period);

        $months = [];
        foreach ($ids as $id) {
            $months[(int) $id] = new BilledMonth($period, $realMinutes[$id], $notes[$id] ?? null, tasks: $tasks[(int) $id] ?? []);
        }

        return $months;
    }

    /**
     * Every reportable task of the clients' month: its real minutes there and its stated figure, a task with a figure
     * and no time of its own in the month included. A figure belongs to a client the way a time entry does.
     *
     * @param  list<int>  $clientIds
     * @return array<int, array<int, array{real: int, billed: ?int}>> by client id, then task id
     */
    public static function taskMonths(array $clientIds, string $period): array
    {
        [$from, $to] = LocalCalendar::monthRange($period);
        $tasks = [];

        TimeEntry::query()
            ->whereIn('client_id', $clientIds)
            ->where('start_time', '>=', $from)
            ->where('start_time', '<', $to)
            ->reportable()
            ->groupBy('client_id', 'task_id')
            ->selectRaw('client_id, task_id, SUM(duration_minutes) as minutes')
            ->get()
            ->each(function (TimeEntry $row) use (&$tasks): void {
                $tasks[(int) $row->client_id][(int) $row->task_id] = ['real' => (int) $row->getAttribute('minutes'), 'billed' => null];
            });

        TaskBilledMonth::query()
            ->join('tasks', 'tasks.id', '=', 'task_billed_months.task_id')
            ->whereIn('task_billed_months.client_id', $clientIds)
            ->where('tasks.is_reportable', true)
            ->where('task_billed_months.period', $period)
            ->get(['task_billed_months.task_id', 'task_billed_months.billed_minutes', 'task_billed_months.client_id'])
            ->each(function (TaskBilledMonth $row) use (&$tasks): void {
                $client = (int) $row->client_id;
                $task = (int) $row->task_id;
                $tasks[$client][$task] = ['real' => $tasks[$client][$task]['real'] ?? 0, 'billed' => (int) $row->billed_minutes];
            });

        foreach (array_keys($tasks) as $client) {
            ksort($tasks[$client]);
        }

        return $tasks;
    }

    /** The month's reportable minutes, counted as the hours bank and the report count them. */
    public static function realMinutes(int $clientId, string $period): int
    {
        [$from, $to] = LocalCalendar::monthRange($period);

        return (int) TimeEntry::query()
            ->where('client_id', $clientId)
            ->where('start_time', '>=', $from)
            ->where('start_time', '<', $to)
            ->reportable()
            ->sum('duration_minutes');
    }

    /** The local month a task was last worked in, else the month it was finished in, else this month. */
    public static function taskPeriod(Task $task): string
    {
        $last = TimeEntry::query()->where('task_id', $task->id)->max('start_time');
        $at = $last !== null ? Carbon::parse($last, 'UTC') : $task->finished_at;

        return $at !== null ? LocalCalendar::monthOf($at) : LocalCalendar::currentMonth();
    }

    /**
     * Every client a task's time or figures count for, so a caller can lock them all at once in id order.
     *
     * @return list<int>
     */
    public static function clientsOfTask(Task $task): array
    {
        return TimeEntry::query()->where('task_id', $task->id)->whereNotNull('client_id')->distinct()->pluck('client_id')
            ->merge(TaskBilledMonth::query()->where('task_id', $task->id)->distinct()->pluck('client_id'))
            ->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
    }

    /** Whether moving the task's time would also move billed figures, which is a billing write. */
    public static function figuresWouldMove(Task $task, ?int $toClientId): bool
    {
        return TaskBilledMonth::query()->where('task_id', $task->id)->when($toClientId !== null, fn ($query) => $query->where('client_id', '!=', $toClientId))->exists();
    }

    /**
     * After a task's time moved onto its project's client, its figures follow, month by month: one in a month settled or
     * reported for either client stays where it was billed, like the time there.
     *
     * @return list<string> the months whose figure stayed
     */
    public function followTask(Task $task): array
    {
        $to = Project::query()->whereKey($task->project_id)->value('client_id');
        $rows = TaskBilledMonth::query()->where('task_id', $task->id)->when($to !== null, fn ($query) => $query->where('client_id', '!=', $to))->get();
        if ($to === null || $rows->isEmpty()) {
            return $rows->pluck('period')->unique()->values()->all();
        }
        $clients = $rows->pluck('client_id')->push($to)->map(fn ($id): int => (int) $id)->unique()->values()->all();

        return $this->lock->clients($clients, function () use ($task, $to, $clients): array {
            // Settled the way the task's time is, so a figure and its time always stay or move together.
            $freezes = $this->policy->loggedTimeFreezes($clients);
            $stayed = [];
            $first = [];
            foreach (TaskBilledMonth::query()->where('task_id', $task->id)->where('client_id', '!=', $to)->lockForUpdate()->get() as $row) {
                $start = SettlementPolicy::monthDates($row->period)[0];
                $settled = $freezes[(int) $row->client_id]->covering($start) !== null || $freezes[(int) $to]->covering($start) !== null;
                if ($settled || TaskBilledMonth::query()->where('task_id', $task->id)->where('client_id', $to)->where('period', $row->period)->exists()) {
                    $stayed[] = $row->period;

                    continue;
                }
                foreach ([(int) $row->client_id, (int) $to] as $client) {
                    $first[$client] = isset($first[$client]) ? min($first[$client], $row->period) : $row->period;
                }
                $row->update(['client_id' => $to]);
            }
            foreach ($first as $client => $period) {
                $this->flagFrom($client, $period);
            }
            sort($stayed);

            return $stayed;
        });
    }

    /** A whole-month report whose hours are not its month's task sum may not be finalized, sent or closed on. */
    public function taskSumRefusal(Client $client, ClientReport $report): ?string
    {
        $period = $report->period_start->format('Y-m');
        if (! $report->coversWholeMonth() || $this->policy->closureFor($client, $period) !== null) {
            return null;
        }
        $tasks = $this->month($client, $period)->live()->billedMinutes();

        return self::minutes((float) $report->actual_hours) === $tasks ? null : __('The report for :month bills :report, but its tasks add up to :tasks: change the task figures or regenerate the report first.', [
            'month' => LocalCalendar::monthName($period),
            'report' => HoursText::plain((float) $report->actual_hours),
            'tasks' => HoursText::plain(self::hours($tasks)),
        ]);
    }

    /**
     * Before a task is deleted its figures go, and every draft they counted in waits for a recalculation; a figure in a
     * closed or reported month refuses the delete, as the task's time there does.
     *
     * @throws PeriodSettled
     */
    public function forgetTask(Task $task): void
    {
        $rows = TaskBilledMonth::query()->where('task_id', $task->id)->get(['id', 'client_id', 'period']);
        if ($rows->isEmpty()) {
            return;
        }
        $clients = $rows->pluck('client_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();

        $this->lock->clients($clients, function () use ($rows, $clients): void {
            $freezes = $this->policy->loggedTimeFreezes($clients);
            foreach ($rows as $row) {
                $start = SettlementPolicy::monthDates($row->period)[0];
                $settled = $freezes[(int) $row->client_id]->covering($start);
                if ($settled !== null) {
                    throw new PeriodSettled((int) $row->client_id, $start, $settled['reason'], $settled['month']);
                }
            }
            foreach ($rows as $row) {
                $this->flagFrom((int) $row->client_id, $row->period);
            }
            TaskBilledMonth::query()->whereKey($rows->pluck('id'))->delete();
        });
    }

    /**
     * A task's time or figures stopped or started counting: every draft from its first month on waits for a recalculation,
     * and a finalized or sent report it counts in, with no closure yet, refuses the change until reopened.
     *
     * @throws PeriodSettled
     */
    public function taskCountChanged(Task $task): void
    {
        $first = TaskBilledMonth::query()->where('task_id', $task->id)->groupBy('client_id')->selectRaw('client_id, MIN(period) AS period')->pluck('period', 'client_id')->all();
        TimeEntry::query()->where('task_id', $task->id)->whereNotNull('client_id')->groupBy('client_id')->selectRaw('client_id, MIN(start_time) AS started')->pluck('started', 'client_id')
            ->each(function (string $started, int|string $client) use (&$first): void {
                $period = LocalCalendar::monthOf(Carbon::parse($started, 'UTC'));
                $first[$client] = isset($first[$client]) ? min($first[$client], $period) : $period;
            });
        if ($first === []) {
            return;
        }

        $this->lock->clients(array_map(intval(...), array_keys($first)), function () use ($task, $first): void {
            foreach ($first as $client => $period) {
                $this->refuseUnderReport($task, (int) $client, (string) $period);
            }
            foreach ($first as $client => $period) {
                $this->flagFrom((int) $client, (string) $period);
            }
        });
    }

    public function month(Client $client, string $period): BilledMonth
    {
        return self::months([$client->id => self::realMinutes($client->id, $period)], $period)[$client->id];
    }

    /**
     * What the billed time dialog and `crm billed` show for a month: both values per task and in total, the balance
     * they lead to, and why a figure cannot change now. A closed month reads its frozen figures.
     *
     * @return array<string, mixed>
     */
    public function figures(Client $client, string $period): array
    {
        $month = $this->bank->month($client, $period);
        $billed = $month->billed;
        $report = $this->governingReport($client, $period);
        $refusal = $this->refusal($client, $period, $report);

        return [
            'client_id' => $client->id,
            'client' => LegalName::short($client->name),
            'period' => $period,
            'real' => $billed?->realHours() ?? self::hours(self::realMinutes($client->id, $period)),
            'billed' => $billed?->billedHours(),
            'tasks' => self::namedTaskRows($client, $period, $billed?->taskRows() ?? []),
            'note' => $billed?->note,
            'used' => round($month->usedHours, 2),
            'source' => $month->source,
            'has_retainer' => $month->hasRetainer() && ! $month->outsideBank,
            'opening' => round($month->openingBalanceHours, 2),
            'available' => $month->availableHours(),
            'closing' => $month->closingBalanceHours(),
            'contracted' => $month->contractedHours,
            'closed' => $month->isClosed(),
            'closed_on' => $month->closedAt?->setTimezone(LocalCalendar::timezone())->toDateString(),
            'closure_id' => $month->closureId,
            'report' => $report !== null ? ['id' => $report->id, 'status' => $report->status, 'sent_on' => $report->sentOn()] : null,
            'refusal' => $refusal?->message,
            'refusal_code' => $refusal?->code,
        ];
    }

    /**
     * Sets a task's billed hours for a month ($period null: the month it was last worked in), or clears them with null
     * so it bills its real time again; $note null keeps the stored note.
     */
    public function setTask(Task $task, ?string $period, ?float $hours, ?string $note = null, ?User $by = null): BilledTimeResult
    {
        if ($hours !== null && ($hours < 0 || $hours > self::MAX_HOURS)) {
            return BilledTimeResult::refused(self::CODE_INVALID, __('Billed hours are a number from 0 to 9999.'));
        }

        $period ??= self::taskPeriod($task);
        if (! LocalCalendar::isMonth($period)) {
            return BilledTimeResult::refused(self::CODE_INVALID, __('The period must be a month as YYYY-MM.'));
        }
        $clientId = Project::query()->whereKey($task->refresh()->project_id)->value('client_id');
        if ($clientId === null) {
            return BilledTimeResult::refused(self::CODE_INVALID, __('The task belongs to no client, so it has no billed time.'));
        }
        $minutes = $hours !== null ? self::minutes($hours) : null;

        return $this->lock->client((int) $clientId, fn (Client $client): BilledTimeResult => $this->writeTask($client, $task, $period, $this->governingReport($client, $period, lock: true), $minutes, $note, $by));
    }

    /** The whole-month report that governs the period, locked when the caller holds ClientFinancialLock. */
    public function governingReport(Client $client, string $period, bool $lock = false): ?ClientReport
    {
        [$start, $end] = SettlementPolicy::monthDates($period);

        return ClientReport::governing(ClientReport::query()
            ->where('client_id', $client->id)
            ->where('period_type', ClientReport::PERIOD_MONTH)
            ->whereDate('period_start', $start)
            ->whereDate('period_end', $end)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get());
    }

    /**
     * The month's task rows with what a reader needs to tell them apart, the most billed first.
     *
     * @param  list<array{task_id: int, real: float, stated: ?float, billed: float}>  $rows
     * @return list<array{task_id: int, task: string, project: ?string, real: float, stated: ?float, billed: float, note: ?string}>
     */
    private static function namedTaskRows(Client $client, string $period, array $rows): array
    {
        $tasks = Task::query()->with('project:id,name')->whereIn('id', array_column($rows, 'task_id'))->get(['id', 'name', 'project_id'])->keyBy('id');
        $notes = TaskBilledMonth::query()->where('client_id', $client->id)->where('period', $period)->pluck('note', 'task_id');
        $named = array_map(fn (array $row): array => [
            'task_id' => $row['task_id'],
            'task' => (string) $tasks->get($row['task_id'])?->name,
            'project' => $tasks->get($row['task_id'])?->project?->name,
            'real' => $row['real'],
            'stated' => $row['stated'],
            'billed' => $row['billed'],
            'note' => $row['stated'] !== null ? ($notes[$row['task_id']] ?? null) : null,
        ], $rows);
        usort($named, fn (array $a, array $b): int => [$b['billed'], $a['task_id']] <=> [$a['billed'], $b['task_id']]);

        return $named;
    }

    /** @param  list<string>  $flagged */
    private static function withFlagged(string $message, array $flagged): string
    {
        return $flagged === []
            ? $message
            : $message.' '.__('Recalculate the report for :month.', ['month' => LocalCalendar::monthName($flagged[0])]);
    }

    /** @throws PeriodSettled when a finalized or sent whole-month report from $from on, not yet closed, counts the task's time or figure */
    private function refuseUnderReport(Task $task, int $clientId, string $from): void
    {
        $client = Client::withTrashed()->find($clientId);
        if ($client === null) {
            return;
        }
        $reports = ClientReport::query()
            ->where('client_id', $clientId)
            ->where('period_type', ClientReport::PERIOD_MONTH)
            ->whereIn('status', [ClientReport::STATUS_FINALIZED, ClientReport::STATUS_SENT])
            ->whereDate('period_start', '>=', LocalCalendar::monthFrom($from)->toDateString())
            // A locking read: the caller's transaction may have read before the client lock, and a plain read would see that older snapshot.
            ->lockForUpdate()
            ->get();
        foreach ($reports as $report) {
            $period = $report->period_start->format('Y-m');
            if (! $report->coversWholeMonth() || $this->policy->closureFor($client, $period) !== null || $this->governingReport($client, $period, lock: true)?->id !== $report->id) {
                continue;
            }
            [$start, $end] = LocalCalendar::monthRange($period);
            $counts = TimeEntry::query()->where('task_id', $task->id)->where('client_id', $clientId)->where('start_time', '>=', $start)->where('start_time', '<', $end)->exists()
                || TaskBilledMonth::query()->where('task_id', $task->id)->where('client_id', $clientId)->where('period', $period)->exists();
            if ($counts) {
                throw new PeriodSettled($clientId, SettlementPolicy::monthDates($period)[0], LoggedTimeFreeze::REPORTED, $period);
            }
        }
    }

    private function flagFrom(int $clientId, string $period): void
    {
        $client = Client::withTrashed()->find($clientId);
        if ($client !== null) {
            $this->policy->flagLaterDrafts($client, Carbon::parse(SettlementPolicy::monthDates($period)[0])->subDay()->toDateString());
        }
    }

    /**
     * A task figure write for a caller holding ClientFinancialLock: the task's row and, when the month has a draft
     * report, that report's hours and billing summary in the same transaction; $minutes null clears the figure.
     */
    private function writeTask(Client $client, Task $task, string $period, ?ClientReport $report, ?int $minutes, ?string $note, ?User $by): BilledTimeResult
    {
        $refusal = $this->refusal($client, $period, $report);
        if ($refusal !== null) {
            return $refusal;
        }
        $task->refresh();
        if ((int) Project::query()->whereKey($task->project_id)->value('client_id') !== $client->id) {
            return BilledTimeResult::refused(self::CODE_INVALID, __('The task moved to another client meanwhile; try again.'));
        }
        if (! $task->is_reportable) {
            return BilledTimeResult::refused(self::CODE_NOT_REPORTABLE, __('Task :id is not reportable, so its time does not count; make it reportable first.', ['id' => $task->id]));
        }

        $current = $this->month($client, $period);

        $row = TaskBilledMonth::query()->where('task_id', $task->id)->where('client_id', $client->id)->where('period', $period)->lockForUpdate()->first();
        $storedNote = $row?->note;
        $newNote = $minutes === null ? null : ($note === null ? $storedNote : (mb_trim($note) !== '' ? mb_trim($note) : null));
        $after = $current->withTaskFigure($task->id, $minutes);
        $reportStale = $report !== null && round((float) $report->actual_hours, 2) !== $after->billedHours();

        if ($minutes === $row?->billed_minutes && $newNote === $storedNote && ! $reportStale) {
            return new BilledTimeResult(BilledTimeResult::UNCHANGED, '');
        }
        $summaryRefusal = $reportStale ? ReportWriter::summaryRefusal(ReportWriter::billedSummary($report, $after->billedHours())['status']) : null;
        if ($summaryRefusal !== null) {
            return BilledTimeResult::refused(self::CODE_REFUSED, $summaryRefusal);
        }

        if ($minutes === null) {
            $row?->delete();
        } elseif ($row === null) {
            TaskBilledMonth::create(['task_id' => $task->id, 'client_id' => $client->id, 'period' => $period, 'billed_minutes' => $minutes, 'note' => $newNote, 'updated_by_user_id' => $by?->id]);
        } else {
            $row->update(['billed_minutes' => $minutes, 'note' => $newNote, 'updated_by_user_id' => $by?->id]);
        }

        if ($reportStale) {
            app(ReportWriter::class)->rewriteActualHours($report, $after->billedHours(), $by);
        }
        $flagged = $reportStale || $after->billedMinutes() !== $current->billedMinutes()
            ? $this->policy->flagLaterDrafts($client, SettlementPolicy::monthDates($period)[1])
            : [];

        $message = __('Task billed: :task, :month in total: :hours.', [
            'task' => HoursText::plain(self::hours($after->taskBilledMinutes($task->id))),
            'month' => LocalCalendar::monthName($period),
            'hours' => HoursText::plain($after->billedHours()),
        ]);

        return new BilledTimeResult(BilledTimeResult::APPLIED, self::withFlagged($message, $flagged), recalculate: $flagged[0] ?? null);
    }

    /** Why the billed figure of $period cannot change now: the settlement rules every report figure answers to, then the report's status. */
    private function refusal(Client $client, string $period, ?ClientReport $report): ?BilledTimeResult
    {
        if ($period > LocalCalendar::currentMonth()) {
            return BilledTimeResult::refused(self::CODE_INVALID, __('A month that has not started has no billed time.'));
        }

        [$start, $end] = SettlementPolicy::monthDates($period);
        $settled = $this->settledRefusal($client, $start, $end);
        if ($settled !== null) {
            return $settled;
        }
        if ($report !== null && $report->status !== ClientReport::STATUS_DRAFT) {
            return BilledTimeResult::refused(self::CODE_NOT_A_DRAFT, __('The report for :month is already finalized or sent; reopen it to change the billed hours.', ['month' => LocalCalendar::monthName($period)]));
        }

        return null;
    }

    private function settledRefusal(Client $client, string $start, string $end): ?BilledTimeResult
    {
        $message = $this->policy->reportFigureRefusal($client, $start, $end);
        if ($message === null) {
            return null;
        }

        return BilledTimeResult::refused($this->policy->earlierFlaggedRefusal($client, $start) !== null ? self::CODE_REFUSED : self::CODE_PERIOD_SETTLED, $message);
    }
}
