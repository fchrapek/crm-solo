<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\ClientConfigData;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use Illuminate\Console\Command;

#[AccountScope(AccountScope::ACTING)]
final class ClientConfig extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'client:config {client : Client id} {--json : Output as JSON}';

    protected $description = "Show a client's month-close config (cohort, ssh, backup path, local repo, invoicing) — the per-client inputs the month-close-site skill needs.";

    public function handle(ClientConfigData $configData, CrmEntityResolver $resolver): int
    {
        try {
            $client = $resolver->client((string) $this->argument('client'), $this->actingIdentity()->account->id);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        }

        $config = $configData->for($client);

        if ($this->option('json')) {
            $this->raw((string) json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($config as $key => $value) {
            $rendered = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : ($value ?? '—');
            $this->raw(sprintf('%-32s %s', $key, $this->literal((string) $rendered)));
        }

        return self::SUCCESS;
    }
}
