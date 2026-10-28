<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\OutboundMessage;

final readonly class SmsResult
{
    /** @param string $status OutboundMessage::SENT, SIMULATED or FAILED */
    public function __construct(
        public string $status,
        public ?string $providerMessageId = null,
        public ?string $error = null,
    ) {}

    public static function simulated(string $id): self
    {
        return new self(OutboundMessage::SIMULATED, $id);
    }
}
