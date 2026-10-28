<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\Account;

/** Records the message as if sent; nothing leaves the server. */
final class SimulatedSmsProvider implements SmsProviderInterface
{
    public function key(): string
    {
        return 'simulator';
    }

    public function send(Account $account, string $to, string $body, string $reference): SmsResult
    {
        return SmsResult::simulated('sim-'.$reference);
    }
}
