<?php

declare(strict_types=1);

namespace App\Services\Messaging\Sender;

use App\Exceptions\SenderUnavailable;
use App\Services\Messaging\MailDelivery;
use Illuminate\Mail\Mailer;
use Throwable;

/** A mailer for one send through an account's own sender, applying mail.to itself since a hand-built mailer skips the global addresses. */
final class AccountMailer
{
    public function __construct(
        private readonly AccountSenders $senders,
        private readonly SmtpHostGuard $guard,
        private readonly SenderTransportFactory $transports,
        private readonly MailDelivery $delivery,
    ) {}

    /**
     * Class and code only, since a transport message can carry the server's reply or the login.
     *
     * @return array{account: int, exception: class-string, code: int|string}
     */
    public static function failureContext(int $accountId, Throwable $e): array
    {
        return ['account' => $accountId, 'exception' => $e::class, 'code' => $e->getCode()];
    }

    /**
     * Send through it at once ($mailable->send($mailer) or raw()), never queue through it.
     *
     * @throws SenderUnavailable
     */
    public function mailer(int $accountId): Mailer
    {
        if (config('app.demo')) {
            throw SenderUnavailable::demo();
        }

        $sender = $this->senders->config($accountId);
        $host = $this->guard->check($sender->host);

        $mailer = new Mailer("account-sender-{$accountId}", app('view'), $this->transports->make($sender, $host), app('events'));
        $mailer->alwaysFrom($sender->fromAddress, $sender->fromName);

        $redirect = $this->delivery->redirect();
        if ($redirect !== null) {
            $name = config('mail.to.name');
            $mailer->alwaysTo($redirect, is_string($name) && $name !== '' ? $name : null);
        }

        return $mailer;
    }
}
