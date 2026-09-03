<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lead;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Agent verb: move a lead through the funnel. Uses Lead::transitionTo, so
 * the append-only stage history — the funnel's measurement — records the hop
 * exactly like a kanban drag.
 */
final class CrmLeadStage extends Command
{
    protected $signature = 'crm:lead-stage {lead : Lead id} {stage : Target stage} {--note= : Optional note on the event} {--json : Machine-readable output}';

    protected $description = 'Move a lead to a stage (append-only history, like the board)';

    public function handle(): int
    {
        $lead = Lead::find((int) $this->argument('lead'));
        if ($lead === null) {
            $this->error('No lead with id ['.$this->argument('lead').'].');

            return self::FAILURE;
        }

        try {
            $lead->transitionTo((string) $this->argument('stage'), $this->option('note') ?: null, null);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->line('Stages: '.implode(', ', Lead::rowStages((string) $lead->pipeline)));

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'id' => $lead->id,
                'stage' => $lead->stage,
                'is_won' => $lead->isWon(),
            ], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Lead #{$lead->id} {$lead->name} → {$lead->stage}".($lead->isWon() ? ' (won — convert in the UI)' : ''));

        return self::SUCCESS;
    }
}
