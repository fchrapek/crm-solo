<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Services\Agent\AgentRefusal;
use App\Services\Leads\LeadNotices;
use Illuminate\Console\Command;

#[AccountScope(AccountScope::OPERATOR)]
final class NotifyPendingLeadIntakes extends Command
{
    protected $signature = 'lead-intake:notify-pending {--retry-failed : Also send again the failed notices of the last 7 days}';

    protected $description = 'Send waiting lead notices of accounts with a sender, up to 7 days old; skip older ones (operator: every account)';

    public function handle(LeadNotices $notices): int
    {
        try {
            $result = $notices->reconcile(null, (bool) $this->option('retry-failed'));
        } catch (AgentRefusal $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(__('Lead notices sent to the queue: :dispatched. Skipped as older than 7 days: :skipped.', ['dispatched' => $result['queued'], 'skipped' => $result['skipped']]));

        return self::SUCCESS;
    }
}
