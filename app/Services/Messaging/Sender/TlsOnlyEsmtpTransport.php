<?php

declare(strict_types=1);

namespace App\Services\Messaging\Sender;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/** Refuses AUTH and every envelope command unless the current socket has completed a TLS handshake. */
final class TlsOnlyEsmtpTransport extends EsmtpTransport
{
    private const string PLAINTEXT_ALLOWED = '/\A(EHLO|HELO|STARTTLS|QUIT|RSET|NOOP)\b/i';

    public function executeCommand(string $command, array $codes): string
    {
        if (preg_match(self::PLAINTEXT_ALLOWED, $command) !== 1 && ! $this->encrypted()) {
            throw new TransportException('The server did not complete TLS, so nothing more was sent.');
        }

        return parent::executeCommand($command, $codes);
    }

    /** PHP adds "crypto" to a socket's metadata only once a handshake on it has succeeded. */
    private function encrypted(): bool
    {
        $socket = (fn () => $this->stream)->call($this->getStream());

        return is_resource($socket) && isset(stream_get_meta_data($socket)['crypto']);
    }
}
