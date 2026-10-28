<?php

declare(strict_types=1);

namespace App\Services\Time;

use RuntimeException;

/** The entry was changed or deleted between the caller reading it and the write taking its lock. */
final class TimeEntryChanged extends RuntimeException
{
    public function __construct(public readonly int $entryId)
    {
        parent::__construct("Entry #{$entryId} changed while it was being edited.");
    }
}
