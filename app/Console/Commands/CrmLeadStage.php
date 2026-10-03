<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Models\Lead;
use App\Services\Agent\ReferenceNotFoundException;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Agent verb: move a lead through the funnel. Uses Lead::transitionTo, so
 * the append-only stage history — the funnel's measurement — records the hop
 * exactly like a kanban drag.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmLeadStage extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:lead-stage {lead : Lead id} {stage : Target stage} {--note= : Optional note on the event} {--json : Machine-readable output}';

    protected $description = 'Move a lead to a stage (append-only history, like the board)';

    public function handle(): int
    {
        $id = (string) $this->argument('lead');
        $lead = ctype_digit($id)
            ? Lead::query()->where('account_id', $this->actingIdentity()->account->id)->find((int) $id)
            : null;
        if ($lead === null) {
            return $this->referenceFailure(new ReferenceNotFoundException('lead', $id));
        }

        try {
            $lead->transitionTo((string) $this->argument('stage'), $this->option('note') ?: null, null);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->line('Stages: '.implode(', ', Lead::rowStages((string) $lead->pipeline)));

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->raw($this->encodeJson([
                'id' => $lead->id,
                'stage' => $lead->stage,
                'is_won' => $lead->isWon(),
            ]));

            return self::SUCCESS;
        }

        $this->raw("Lead #{$lead->id} ".$this->literal($lead->name)." -> {$lead->stage}".($lead->isWon() ? ' (won, convert in the UI)' : ''));

        return self::SUCCESS;
    }
}
