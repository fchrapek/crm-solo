<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use RuntimeException;
use Throwable;

final class InfaktApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Infakt API request failed: HTTP %d %s', $status, mb_substr($body, 0, 300)),
            0,
            $previous,
        );
    }

    public function userMessage(): string
    {
        if ($this->status === 401 || $this->status === 403) {
            return __('Infakt sync failed: API key was rejected (HTTP :status). Generate a new key in Infakt and paste it here.', [
                'status' => $this->status,
            ]);
        }

        return __('Infakt sync failed: HTTP :status. :body', [
            'status' => $this->status,
            'body' => mb_substr($this->body, 0, 200),
        ]);
    }
}
