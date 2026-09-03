<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Lead;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Agent verb: capture a lead from the terminal (outbound research sessions
 * produce leads). Goes through the Lead model, so pipeline/source validation,
 * entry-stage resolution and the capture event all behave exactly like the
 * UI form.
 */
final class CrmLeadCapture extends Command
{
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

    public function handle(): int
    {
        $account = Account::query()->orderBy('id')->first();
        if ($account === null) {
            $this->error('No account exists.');

            return self::FAILURE;
        }

        $name = mb_trim((string) $this->option('name'));
        $source = mb_trim((string) $this->option('source'));
        if ($name === '' || $source === '') {
            $this->error('--name and --source are required. Sources: '.implode(', ', Lead::sources()));

            return self::FAILURE;
        }

        try {
            $lead = Lead::create([
                'account_id' => $account->id,
                'pipeline' => $this->option('pipeline') ?: (Lead::pipelines()[0] ?? ''),
                'name' => $name,
                'source' => $source,
                'email' => $this->option('email') ?: null,
                'phone' => $this->option('phone') ?: null,
                'company' => $this->option('company') ?: null,
                'notes' => $this->option('note') ?: null,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'id' => $lead->id,
                'pipeline' => $lead->pipeline,
                'stage' => $lead->stage,
            ], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Captured lead #{$lead->id} {$lead->name} [{$lead->pipeline}] at {$lead->stage}.");

        return self::SUCCESS;
    }
}
