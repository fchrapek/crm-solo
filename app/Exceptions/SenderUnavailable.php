<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Why an account's own sender cannot send now, in our own words without any credential. */
final class SenderUnavailable extends RuntimeException
{
    public const string DEMO = 'demo';

    public const string NONE = 'none';

    public const string UNREADABLE = 'unreadable';

    /** The host resolves to an address that is not public: permanent until the sender changes. */
    public const string HOST = 'host';

    /** The host did not resolve this time: worth another try. */
    public const string UNRESOLVED = 'unresolved';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function demo(): self
    {
        return new self(self::DEMO, __('The demo sends no email.'));
    }

    public static function none(int $accountId): self
    {
        return new self(self::NONE, __('Account :account has no enabled sender with a password.', ['account' => $accountId]));
    }

    public static function unreadable(int $accountId): self
    {
        return new self(self::UNREADABLE, __('The sender password of account :account was stored under another APP_KEY; set it again.', ['account' => $accountId]));
    }

    public static function host(string $message): self
    {
        return new self(self::HOST, $message);
    }

    public static function unresolved(string $message): self
    {
        return new self(self::UNRESOLVED, $message);
    }
}
