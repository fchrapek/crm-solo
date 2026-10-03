<?php

declare(strict_types=1);

namespace App\Services\Integrations\Trello;

/** What a CappedFileSink has taken for the current response, and whether it refused the rest. */
final class CappedFileSinkState
{
    public bool $refused = false;

    public int $written = 0;

    public function refuse(): void
    {
        $this->refused = true;
    }
}
