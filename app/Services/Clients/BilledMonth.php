<?php

declare(strict_types=1);

namespace App\Services\Clients;

/**
 * A client's month in both values: real time as logged, and the billed figure the client is charged, the sum of the
 * month's tasks, each the figure stated for it or else its real time. Once a closure or a governing report settles the
 * month, its used hours are the billed figure everywhere ($settledHours). Hours keep 2 places.
 */
final readonly class BilledMonth
{
    public function __construct(
        public string $period,
        public int $realMinutes,
        public ?string $note = null,
        public ?float $settledHours = null,
        /** @var array<int, array{real: int, billed: ?int, frozen?: int}> by task id: its reportable minutes, stated figure and, from a closure, the figure it was frozen at */
        public array $tasks = [],
    ) {}

    /**
     * A closed month's frozen figures. A closure made before billed time existed (schema 1) has only its live
     * reportable hours ('entries') and the used figure, which stand in.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromSnapshot(string $period, array $snapshot): ?self
    {
        $billed = $snapshot['billed_time'] ?? null;
        if (! is_array($billed)) {
            return isset($snapshot['entries'], $snapshot['used'])
                ? new self($period, BilledTime::minutes((float) $snapshot['entries']), settledHours: round((float) $snapshot['used'], 2))
                : null;
        }

        $tasks = [];
        foreach ((array) ($billed['tasks'] ?? []) as $task) {
            $tasks[(int) $task['task_id']] = [
                'real' => BilledTime::minutes((float) $task['real']),
                'billed' => isset($task['stated']) ? BilledTime::minutes((float) $task['stated']) : null,
                // A closure made under rounding froze a figure that neither stated nor real gives back.
                'frozen' => BilledTime::minutes((float) $task['billed']),
            ];
        }

        return new self(
            period: $period,
            realMinutes: BilledTime::minutes((float) $billed['real']),
            settledHours: round((float) ($snapshot['used'] ?? $billed['billed']), 2),
            tasks: $tasks,
        );
    }

    /** The same month with the figure a closure or a governing report settled it at. */
    public function settledAt(float $hours): self
    {
        return new self($this->period, $this->realMinutes, $this->note, round($hours, 2), $this->tasks);
    }

    public function withNote(?string $note): self
    {
        return new self($this->period, $this->realMinutes, $note, $this->settledHours, $this->tasks);
    }

    /** The month as its tasks bill it now, whatever a report or closure settled it at. */
    public function live(): self
    {
        return new self($this->period, $this->realMinutes, $this->note, null, $this->tasks);
    }

    /** The same month with one task's stated figure set, or dropped with null. */
    public function withTaskFigure(int $taskId, ?int $minutes): self
    {
        $tasks = $this->tasks;
        $tasks[$taskId] = ['real' => $tasks[$taskId]['real'] ?? 0, 'billed' => $minutes];

        return new self($this->period, $this->realMinutes, $this->note, $this->settledHours, $tasks);
    }

    public function taskBilledMinutes(int $taskId): int
    {
        $task = $this->tasks[$taskId] ?? ['real' => 0, 'billed' => null];

        return $task['billed'] ?? $task['frozen'] ?? $task['real'];
    }

    /** The sum of the task figures; a month known only by its total (an early closure) is its real time. */
    public function tasksBilledMinutes(): int
    {
        if ($this->tasks === []) {
            return $this->realMinutes;
        }

        return array_sum(array_map(fn (int $id): int => $this->taskBilledMinutes($id), array_keys($this->tasks)));
    }

    public function billedMinutes(): int
    {
        return $this->settledHours !== null ? BilledTime::minutes($this->settledHours) : $this->tasksBilledMinutes();
    }

    public function realHours(): float
    {
        return BilledTime::hours($this->realMinutes);
    }

    public function billedHours(): float
    {
        return $this->settledHours ?? BilledTime::hours($this->billedMinutes());
    }

    /** @return list<array{task_id: int, real: float, stated: ?float, billed: float}> */
    public function taskRows(): array
    {
        $rows = [];
        foreach ($this->tasks as $id => $task) {
            $rows[] = [
                'task_id' => $id,
                'real' => BilledTime::hours($task['real']),
                'stated' => $task['billed'] !== null ? BilledTime::hours($task['billed']) : null,
                'billed' => BilledTime::hours($this->taskBilledMinutes($id)),
            ];
        }

        return $rows;
    }

    /** @return array{real: float, billed: float, tasks: list<array{task_id: int, real: float, stated: ?float, billed: float}>} */
    public function toSnapshot(): array
    {
        return [
            'real' => $this->realHours(),
            'billed' => $this->billedHours(),
            'tasks' => $this->taskRows(),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [...$this->toSnapshot(), 'note' => $this->note];
    }
}
