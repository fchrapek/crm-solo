<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class StdinSecretInput implements SecretInput
{
    private const int MAX_BYTES = 4096;

    /** @param  resource|null  $stream  STDIN unless a test passes its own */
    public function __construct(private readonly mixed $stream = null) {}

    public function line(): ?string
    {
        $stream = $this->stream ?? (defined('STDIN') ? STDIN : null);
        if (! is_resource($stream) || stream_isatty($stream)) {
            return null;
        }

        $raw = (string) stream_get_contents($stream, self::MAX_BYTES + 1);
        if (mb_strlen($raw, '8bit') > self::MAX_BYTES) {
            throw new InvalidArgumentException(__('More than 4 KB came in on stdin: pipe only the secret.'));
        }

        $line = (string) preg_replace('/\r?\n\z/', '', $raw);
        if (str_contains($line, "\n") || str_contains($line, "\r")) {
            throw new InvalidArgumentException(__('Several lines came in on stdin: pipe only the secret, on one line.'));
        }

        return $line;
    }
}
