<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Models\Lead;
use App\Services\Leads\LeadCapture;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Agent verb: capture a lead from the terminal (outbound research sessions
 * produce leads). Goes through the Lead model, so pipeline/source validation,
 * entry-stage resolution and the capture event all behave exactly like the
 * UI form.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmLeadCapture extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:lead-capture
        {--pipeline= : Brand pipeline (config/leadgen.php); defaults to the first}
        {--name= : Lead name (required)}
        {--source= : Source slug (required — immutable attribution)}
        {--email=}
        {--phone=}
        {--company=}
        {--note= : Stored as the lead notes}
        {--json : Machine-readable output}';

    protected $description = 'Capture a lead (validated exactly like the UI: pipeline/source/entry stage)';

    public function handle(LeadCapture $capture): int
    {
        $account = $this->actingIdentity()->account;

        $name = mb_trim((string) $this->option('name'));
        $source = mb_trim((string) $this->option('source'));
        if ($name === '' || $source === '') {
            $this->error('--name and --source are required. Sources: '.implode(', ', Lead::sources()));

            return self::FAILURE;
        }

        try {
            $lead = $capture->capture($account, [
                'pipeline' => $this->option('pipeline'),
                'name' => $name,
                'source' => $source,
                'email' => $this->option('email'),
                'phone' => $this->option('phone'),
                'company' => $this->option('company'),
                'notes' => $this->option('note'),
            ]);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->raw($this->encodeJson([
                'id' => $lead->id,
                'pipeline' => $lead->pipeline,
                'stage' => $lead->stage,
            ]));

            return self::SUCCESS;
        }

        $this->raw("Captured lead #{$lead->id} ".$this->literal($lead->name)." [{$lead->pipeline}] at {$lead->stage}.");

        return self::SUCCESS;
    }
}
