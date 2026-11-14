<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * Whether an email to a client may really reach the client. Read from the
 * process that sends, so a queue worker decides with its own configuration.
 * It does not read APP_ENV: both hosted stacks run as production.
 */
final class MailDelivery
{
    /** Reaches the client: the only mode that counts as sent. */
    public const string LIVE = 'live';

    /** Reaches nobody outside: the log, the array transport or the redirect address. */
    public const string TEST = 'test';

    /** Would reach the client while live sending is off, or through a composite mailer: nothing goes out. */
    public const string REFUSED = 'refused';

    /** Transports that deliver to nobody; only these, or the redirect, make a test send. */
    private const array NON_DELIVERING = ['log', 'array'];

    /** A failover may end in the log after a real send failed, a round robin retries another provider by itself: neither is one outcome. */
    private const array COMPOSITE = ['failover', 'roundrobin'];

    public function mode(): string
    {
        $silent = in_array($this->transport(), self::NON_DELIVERING, true);
        if (config('app.demo')) {
            return $silent ? self::TEST : self::REFUSED;
        }
        if ($this->redirect() !== null || $silent) {
            return self::TEST;
        }
        if (in_array($this->transport(), self::COMPOSITE, true)) {
            return self::REFUSED;
        }

        return config('mail.live_send') === true ? self::LIVE : self::REFUSED;
    }

    public function redirect(): ?string
    {
        $address = config('mail.to.address');

        return is_string($address) && $address !== '' ? $address : null;
    }

    public function transport(): string
    {
        $mailer = (string) config('mail.default');

        return (string) config("mail.mailers.{$mailer}.transport", $mailer);
    }

    public function refusal(): string
    {
        if (config('app.demo')) {
            return 'The demo sends no email. Nothing was sent.';
        }

        return in_array($this->transport(), self::COMPOSITE, true)
            ? 'A composite mailer cannot send a client email: set MAIL_MAILER to a single transport. Nothing was sent.'
            : 'Live sending is off (MAIL_LIVE_SEND) and no redirect address is set (MAIL_ALWAYS_TO), so nothing was sent.';
    }
}
