<?php

declare(strict_types=1);

namespace App\Services\Messaging\Sender;

use App\Exceptions\SenderUnavailable;
use App\Services\Messaging\MailDelivery;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/** One throttled test send through an account's own sender, for the server command and the owner's settings form alike. */
final class SenderTests
{
    public const int PER_TEN_MINUTES = 3;

    public function __construct(
        private readonly AccountMailer $mailers,
        private readonly AccountSenders $senders,
        private readonly MailDelivery $delivery,
    ) {}

    public static function throttleKey(int $accountId): string
    {
        return "mail-sender-test|{$accountId}";
    }

    /**
     * Sends the test and remembers how it went; the message is generic, never the server's words.
     *
     * @return array{ok: bool, message: string}
     */
    public function send(int $accountId, ?string $to = null): array
    {
        if (config('app.demo')) {
            return ['ok' => false, 'message' => __(':reason Nothing was sent.', ['reason' => SenderUnavailable::demo()->getMessage()])];
        }

        $key = self::throttleKey($accountId);
        if (RateLimiter::tooManyAttempts($key, self::PER_TEN_MINUTES)) {
            return ['ok' => false, 'message' => trans_choice('Too many test sends; try again in :count minute.', (int) ceil(RateLimiter::availableIn($key) / 60))];
        }

        try {
            $sender = $this->senders->config($accountId);
            $to = is_string($to) && $to !== '' ? $to : $sender->fromAddress;
            if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                return ['ok' => false, 'message' => __('--to is not an email address. Nothing was sent.')];
            }

            $mailer = $this->mailers->mailer($accountId);
            RateLimiter::hit($key, 600);
            $mailer->raw(
                __('This message was sent by CRM Solo through this account\'s own mailbox, to check the sender. Nothing else needs to happen.'),
                fn (Message $message) => $message->to($to)->subject(__('CRM Solo sender test')),
            );
        } catch (SenderUnavailable $e) {
            $this->senders->recordTest($accountId, $e->reason);

            return ['ok' => false, 'message' => __(':reason Nothing was sent.', ['reason' => $e->getMessage()])];
        } catch (Throwable $e) {
            $this->senders->recordTest($accountId, 'transport');
            Log::warning('Account sender test failed.', AccountMailer::failureContext($accountId, $e));

            return ['ok' => false, 'message' => __('The test email was not sent. Check the host, port, login and password, and that the server offers TLS.')];
        }

        $this->senders->recordTest($accountId, null);
        $redirect = $this->delivery->redirect();

        return ['ok' => true, 'message' => $redirect !== null
            ? __('Test email sent to :redirect (MAIL_ALWAYS_TO) instead of :to.', ['redirect' => $redirect, 'to' => $to])
            : __('Test email sent to :to.', ['to' => $to])];
    }
}
