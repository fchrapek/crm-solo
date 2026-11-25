<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\SenderUnavailable;
use App\Mail\LeadIntakeNotice;
use App\Models\Lead;
use App\Models\LeadIntake;
use App\Services\Messaging\Sender\AccountMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Sends one lead notice through the account's own sender, holding only the intake id and reading everything else at send time. */
final class SendLeadIntakeNotice implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** SMTP reply codes for a refused login: retrying with the same password only repeats the refusal. */
    private const array AUTH_CODES = [530, 534, 535];

    public int $tries = 5;

    /** Below the queue's retry_after (90 s), so a running send is never handed to a second worker. */
    public int $timeout = 60;

    /** Longer than the five tries with their backoff, so a retrying notice is never queued twice. */
    public int $uniqueFor = 7200;

    public function __construct(public readonly int $intakeId) {}

    /** Queues the notice once per intake after the commit, true only when it was pushed now; a refused push frees the lock. */
    public static function enqueue(int $intakeId): bool
    {
        $pushed = false;
        DB::afterCommit(function () use ($intakeId, &$pushed): void {
            $pushed = self::push($intakeId);
        });

        return $pushed;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function uniqueId(): string
    {
        return (string) $this->intakeId;
    }

    public function handle(AccountMailer $mailers): void
    {
        try {
            $this->deliver($mailers);
        } catch (Throwable $e) {
            Log::warning('Lead notice send failed.', ['intake' => $this->intakeId, 'exception' => $e::class, 'code' => $e->getCode()]);

            // A new exception without the original: its message and bindings can carry the visitor's data.
            throw new RuntimeException('Lead notice send failed: '.$e::class.' '.$e->getCode());
        }
    }

    public function failed(?Throwable $e): void
    {
        $intake = LeadIntake::query()->find($this->intakeId);
        if ($intake !== null && $intake->notice_state === LeadIntake::WAITING) {
            self::markFailed($intake, 'tries');
        }
    }

    private static function push(int $intakeId): bool
    {
        $job = new self($intakeId);
        $lock = new UniqueLock(app(Cache::class));
        if (! $lock->acquire($job)) {
            return false;
        }

        try {
            app(Dispatcher::class)->dispatch($job);
        } catch (Throwable $e) {
            $lock->release($job);
            Log::warning('Lead notice dispatch failed.', ['intake' => $intakeId, 'exception' => $e::class]);

            return false;
        }

        return true;
    }

    private static function markFailed(LeadIntake $intake, string $reason): void
    {
        $intake->update(['notice_state' => LeadIntake::FAILED, 'failure_reason' => $reason]);
        Log::warning('Lead notice failed.', ['intake' => $intake->id, 'reason' => $reason]);
    }

    private function deliver(AccountMailer $mailers): void
    {
        $intake = LeadIntake::query()->find($this->intakeId);
        if ($intake === null || $intake->notice_state !== LeadIntake::WAITING) {
            return;
        }
        if ($intake->created_at === null || $intake->created_at->lt(now()->subDays(LeadIntake::MAX_AGE_DAYS))) {
            $intake->update(['notice_state' => LeadIntake::SKIPPED]);

            return;
        }

        $lead = Lead::withTrashed()->find($intake->lead_id);
        if ($lead === null || $lead->trashed()) {
            $intake->update(['notice_state' => LeadIntake::SKIPPED]);

            return;
        }

        $form = $intake->form;
        $recipient = $form?->notify_to;
        if ($form === null || $recipient === null || $recipient === '') {
            self::markFailed($intake, 'no_recipient');

            return;
        }

        try {
            $mailer = $mailers->mailer((int) $intake->account_id);
        } catch (SenderUnavailable $e) {
            match ($e->reason) {
                // Without a usable sender the notice waits; lead-intake:notify-pending sends it once one is set.
                SenderUnavailable::NONE, SenderUnavailable::UNREADABLE => null,
                SenderUnavailable::UNRESOLVED => $this->retry($intake, $e),
                default => self::markFailed($intake, $e->reason),
            };

            return;
        }

        $earlier = Lead::query()
            ->where('account_id', $intake->account_id)
            ->where('id', '<', $lead->id)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $lead->email)])
            ->orderByDesc('id')
            ->first();

        $intake->increment('notify_attempts');
        try {
            (new LeadIntakeNotice($lead, $form, $earlier, $recipient))->send($mailer);
        } catch (Throwable $e) {
            if (in_array((int) $e->getCode(), self::AUTH_CODES, true)) {
                $this->failAccountOnAuth((int) $intake->account_id);

                return;
            }

            throw $e;
        }

        $intake->update(['notice_state' => LeadIntake::SENT, 'notified_at' => now()]);
    }

    private function retry(LeadIntake $intake, SenderUnavailable $e): never
    {
        $intake->increment('notify_attempts');

        throw $e;
    }

    /** A refused login fails every waiting notice of the account, so a wrong password is tried once, not once per notice. */
    private function failAccountOnAuth(int $accountId): void
    {
        $count = LeadIntake::query()
            ->where('account_id', $accountId)
            ->where('notice_state', LeadIntake::WAITING)
            ->update(['notice_state' => LeadIntake::FAILED, 'failure_reason' => 'auth']);
        Log::warning('Lead notices failed: the sender refused the login.', ['account' => $accountId, 'notices' => $count]);
    }
}
