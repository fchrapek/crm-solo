<?php

declare(strict_types=1);

namespace App\Services\Day;

use Illuminate\Validation\ValidationException;

/** Another change of the account's plan held it past the wait: nothing was written, and the same request can be sent again. */
final class PlanBusy extends ValidationException
{
    public static function on(string $field): self
    {
        return self::withMessages([$field => __('The plan is being changed. Try again.')]);
    }
}
