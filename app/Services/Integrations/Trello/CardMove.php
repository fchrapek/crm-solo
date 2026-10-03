<?php

declare(strict_types=1);

namespace App\Services\Integrations\Trello;

use Carbon\CarbonInterface;

/**
 * What the sync learnt about when a card last changed list: Trello answered
 * (with the time, or with no move at all), or the question is still open
 * because the lookup failed or was not made.
 */
final readonly class CardMove
{
    private function __construct(
        public bool $answered,
        public ?CarbonInterface $at,
    ) {}

    public static function answered(?CarbonInterface $at): self
    {
        return new self(true, $at);
    }

    public static function unknown(): self
    {
        return new self(false, null);
    }
}
