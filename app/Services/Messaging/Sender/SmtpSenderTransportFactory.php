<?php

declare(strict_types=1);

namespace App\Services\Messaging\Sender;

use LogicException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/** Connects to the checked address with the host name as the verified TLS peer, TLS required on every port. */
final class SmtpSenderTransportFactory implements SenderTransportFactory
{
    public const int TIMEOUT_SECONDS = 10;

    public function make(SenderConfig $sender, CheckedHost $host): TransportInterface
    {
        $address = str_contains($host->address, ':') ? "[{$host->address}]" : $host->address;
        $implicitTls = $sender->port === 465;
        $transport = $host->public
            ? new TlsOnlyEsmtpTransport($address, $sender->port, $implicitTls)
            : new EsmtpTransport($address, $sender->port, $implicitTls);

        $this->secure($transport, $host);

        if ($host->public) {
            $transport->setRequireTls(true);
        } else {
            $transport->setAutoTls(false);
        }

        $localDomain = config('mail.mailers.smtp.local_domain');
        if (is_string($localDomain) && $localDomain !== '') {
            $transport->setLocalDomain($localDomain);
        }

        if ($sender->username !== '') {
            $transport->setUsername($sender->username);
            $transport->setPassword($sender->password());
        }

        return $transport;
    }

    /** @throws LogicException when the stream is not a socket, so no option could be set */
    public function secure(EsmtpTransport $transport, CheckedHost $host): void
    {
        $stream = $transport->getStream();
        if (! $stream instanceof SocketStream) {
            throw new LogicException('The sender transport has no socket stream to secure.');
        }
        $stream->setTimeout(self::TIMEOUT_SECONDS);
        $stream->setStreamOptions(['ssl' => [
            'peer_name' => $host->host,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]]);
    }
}
