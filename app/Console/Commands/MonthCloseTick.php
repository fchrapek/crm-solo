<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Agent\MonthCloseTicker;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class MonthCloseTick extends Command
{
    protected $signature = 'month-close:tick
                            {client : Client id}
                            {step : Step key (e.g. db_dump), or "list" to just show the checklist}
                            {state? : done | skipped | pending}
                            {--project= : Site id or name fragment — required when the step repeats across a client\'s sites}
                            {--note= : Why — worth recording whenever a step is skipped}
                            {--period= : Billed month YYYY-MM (default: previous month)}
                            {--json : Machine-readable output}';

    protected $description = 'Tick a month-close checklist step for a client (starts the run if needed). Use step="list" to show the checklist.';

    public function handle(MonthCloseTicker $ticker): int
    {
        $client = Client::find($this->argument('client'));

        if ($client === null) {
            $this->error('Client not found.');

            return self::FAILURE;
        }

        $period = $this->option('period') ? (string) $this->option('period') : $ticker->defaultPeriod();
        $stepKey = (string) $this->argument('step');

        try {
            $checklist = $stepKey === 'list'
                ? $ticker->status($client, $period)
                : $ticker->tick(
                    $client,
                    $period,
                    $stepKey,
                    (string) $this->argument('state'),
                    null,
                    $this->option('project') !== null ? (string) $this->option('project') : null,
                    $this->option('note') !== null ? (string) $this->option('note') : null,
                );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($checklist, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($stepKey !== 'list') {
            $this->info("{$client->name} [{$period}] · {$stepKey} → {$this->argument('state')}");
        }

        return $this->showChecklist($checklist);
    }

    /**
     * @param  array<string, mixed>  $checklist
     */
    private function showChecklist(array $checklist): int
    {
        $this->table(
            ['site', 'step', 'state'],
            collect($checklist['steps'])
                ->map(fn (array $step): array => [$step['project'] ?? '—', $step['key'], $step['state']])
                ->all(),
        );
        $this->line("{$checklist['client']} [{$checklist['period']}] — status: {$checklist['status']} ({$checklist['resolved']}/{$checklist['total']})");

        return self::SUCCESS;
    }
}
