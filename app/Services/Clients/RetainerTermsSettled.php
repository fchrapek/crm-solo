<?php

declare(strict_types=1);

namespace App\Services\Clients;

use RuntimeException;

/** Unwinds the transaction of a refused retainer change. */
final class RetainerTermsSettled extends RuntimeException
{
    public function __construct(public readonly string $period)
    {
        parent::__construct("Retainer terms of {$period} are settled.");
    }
}
