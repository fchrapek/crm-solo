<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Leads\LeadNotices;
use Illuminate\Console\Command;

/** Agent verb: send the account's failed and waiting lead notices of the last 7 days again, as lead-intake:notify-pending --retry-failed does. */
#[AccountScope(AccountScope::ACTING)]
final class CrmLeadIntakeRetry extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:lead-intake-retry {--json : Machine-readable output}';

    protected $description = 'Queue the failed and waiting lead notices of the last 7 days again, for an account with a sender (after a fixed password, for example)';

    public function handle(LeadNotices $notices): int
    {
        try {
            $result = $notices->reconcile($this->actingIdentity()->account->id, true);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($result));

            return self::SUCCESS;
        }

        $this->raw("Lead notices: {$result['retried']} failed ones back to waiting, {$result['queued']} queued, {$result['skipped']} older than 7 days skipped.");

        return self::SUCCESS;
    }
}
