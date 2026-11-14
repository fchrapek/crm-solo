<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Messaging\SummaryEmails;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** One claimed attempt of a client summary email. Never retried by the queue: a second run could send twice. */
final class SendSummaryEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** Below the queue's retry_after (90 s), so the queue never hands a running send to a second worker. */
    public int $timeout = 60;

    public function __construct(public readonly int $emailId, public readonly int $attempt) {}

    public function handle(SummaryEmails $emails): void
    {
        $emails->deliver($this->emailId, $this->attempt);
    }
}
