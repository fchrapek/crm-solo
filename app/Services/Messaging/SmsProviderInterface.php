<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\Account;

/**
 * A text-message gateway (a simulator today; SMSAPI.pl and others later).
 * A provider that cannot tell whether a message left returns FAILED with the
 * reason: the dispatcher never resends on its own.
 */
interface SmsProviderInterface
{
    public function key(): string;

    /** @param string $to E.164, e.g. +48100100200 */
    public function send(Account $account, string $to, string $body, string $reference): SmsResult;
}
