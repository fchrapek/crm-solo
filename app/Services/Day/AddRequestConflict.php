<?php

declare(strict_types=1);

namespace App\Services\Day;

use RuntimeException;

/** A request id sent again that cannot be replayed: it carried another payload, or the task it made is gone. */
final class AddRequestConflict extends RuntimeException
{
    public static function otherPayload(): self
    {
        return new self(__('This request already added a different task.'));
    }

    public static function taskGone(): self
    {
        return new self(__('The task this request added was deleted or left the day.'));
    }
}
