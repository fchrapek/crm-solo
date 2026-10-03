<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\MonthCloseTicker;
use App\Services\Agent\ReferenceException;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatter;

#[AccountScope(AccountScope::ACTING)]
final class MonthCloseTick extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'month-close:tick
                            {client : Client id}
                            {step : Step key (e.g. db_dump), or "list" to just show the checklist}
                            {state? : done | skipped | pending}
                            {--project= : Site id or name fragment — required when the step repeats across a client\'s sites}
                            {--note= : Why — worth recording whenever a step is skipped}
                            {--period= : Billed month YYYY-MM (default: previous month)}
                            {--json : Machine-readable output}';

    protected $description = 'Tick a month-close checklist step for a client (starts the run if needed). Use step="list" to show the checklist.';

    public function handle(MonthCloseTicker $ticker, CrmEntityResolver $resolver): int
    {
        try {
            $client = $resolver->client((string) $this->argument('client'), $this->actingIdentity()->account->id);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
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
            $this->raw($this->encodeJson($checklist));

            return self::SUCCESS;
        }

        if ($stepKey !== 'list') {
            $this->raw($this->literal($client->name).' ['.$this->literal($period).'] · '.$this->literal($stepKey).' -> '.$this->literal((string) $this->argument('state')));
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
                ->map(fn (array $step): array => [OutputFormatter::escape($this->literal($step['project'] ?? '-')), $step['key'], $step['state']])
                ->all(),
        );
        $this->raw($this->literal($checklist['client'])." [{$checklist['period']}] - status: {$checklist['status']} ({$checklist['resolved']}/{$checklist['total']})");

        return self::SUCCESS;
    }
}
