<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\Account;
use InvalidArgumentException;

/** The account's text-message provider, chosen in its settings (`messages.provider`). */
final class SmsProviderRegistry
{
    /** @var array<string, class-string<SmsProviderInterface>> */
    private const array PROVIDERS = [
        'simulator' => SimulatedSmsProvider::class,
        'smsapi' => SmsapiProvider::class,
    ];

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public function for(Account $account): SmsProviderInterface
    {
        $key = (string) ($account->settings['messages']['provider'] ?? 'simulator');
        $class = self::PROVIDERS[$key] ?? throw new InvalidArgumentException("Unknown SMS provider {$key}.");

        return app($class);
    }
}
