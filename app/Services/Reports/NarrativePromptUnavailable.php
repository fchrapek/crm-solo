<?php

declare(strict_types=1);

namespace App\Services\Reports;

use RuntimeException;

/**
 * No report prompt could be resolved: the shipped file is missing or
 * unreadable and the account has no override. The AI composer logs it and
 * degrades to the structured composer; `reports:prompt-show` reports it.
 */
final class NarrativePromptUnavailable extends RuntimeException
{
    public static function forPath(string $path): self
    {
        return new self(
            "The report narrative prompt could not be read from [{$path}] and no ".
            'account override is set. Restore the file or import a prompt with '.
            '`php artisan reports:prompt-import <path>`.'
        );
    }
}
