<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Jobs\SendLeadIntakeNotice;
use App\Models\Integration;
use App\Models\LeadIntake;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\AgentWriteRecorder;

/** Queues waiting lead notices of accounts with a sender and skips stale ones, for the scheduled reconcile and the agent retry alike. */
final class LeadNotices
{
    /** A fresh notice is still on its own dispatch. */
    public const int GRACE_MINUTES = 10;

    /**
     * @param  int|null  $accountId  null for every account (the scheduler), else one account
     * @param  int|null  $formId  one form of that account only, as its settings page retries it
     * @return array{queued: int, skipped: int, retried: int}
     *
     * @throws AgentRefusal
     */
    public function reconcile(?int $accountId, bool $retryFailed, ?int $formId = null): array
    {
        if (config('app.demo')) {
            throw new AgentRefusal(AgentRefusal::REFUSED, __('The demo sends no email.'));
        }

        $scoped = fn () => LeadIntake::query()
            ->when($accountId !== null, fn ($query) => $query->where('account_id', $accountId))
            ->when($formId !== null, fn ($query) => $query->where('intake_form_id', $formId));
        $cutoff = now()->subDays(LeadIntake::MAX_AGE_DAYS);

        $skipped = $scoped()
            ->whereIn('notice_state', $retryFailed ? [LeadIntake::WAITING, LeadIntake::FAILED] : [LeadIntake::WAITING])
            ->where('created_at', '<', $cutoff)
            ->update(['notice_state' => LeadIntake::SKIPPED]);

        $withSender = Integration::query()->where('provider', Integration::SMTP)->where('is_enabled', true)->select('account_id');

        $retried = 0;
        if ($retryFailed) {
            $recorder = app(AgentWriteRecorder::class);
            $scoped()->where('notice_state', LeadIntake::FAILED)->whereIn('account_id', $withSender)
                ->lazyById(200)
                ->each(function (LeadIntake $intake) use (&$retried, $cutoff, $recorder): void {
                    // Conditional on the state read, so a notice a worker sent in between stays sent.
                    $moved = LeadIntake::query()
                        ->whereKey($intake->id)
                        ->where('notice_state', LeadIntake::FAILED)
                        ->where('created_at', '>=', $cutoff)
                        ->update(['notice_state' => LeadIntake::WAITING, 'failure_reason' => null, 'notify_attempts' => 0]);
                    if ($moved === 1) {
                        $recorder->recordUpdate($intake, ['notice_state' => LeadIntake::FAILED, 'failure_reason' => $intake->failure_reason, 'notify_attempts' => $intake->notify_attempts]);
                        $retried++;
                    }
                });
        }

        $queued = 0;
        $scoped()
            ->where('notice_state', LeadIntake::WAITING)
            ->where('created_at', '<=', now()->subMinutes(self::GRACE_MINUTES))
            ->whereIn('account_id', $withSender)
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use (&$queued): void {
                $queued += (int) SendLeadIntakeNotice::enqueue($id);
            });

        return ['queued' => $queued, 'skipped' => $skipped, 'retried' => $retried];
    }
}
