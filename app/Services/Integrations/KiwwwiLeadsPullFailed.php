<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use RuntimeException;
use Throwable;

/**
 * The lead endpoint could not be read. The message and context are safe to
 * log: endpoint path and status only, never the base URL or credentials.
 */
final class KiwwwiLeadsPullFailed extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, private readonly array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
