<?php

declare(strict_types=1);

namespace App\Services\Reports;

use RuntimeException;

/**
 * No report prompt could be resolved: the shipped file is missing or
 * unreadable and the account has no override.
 *
 * Deliberately fatal rather than a silent downgrade to the deterministic
 * composer. A missing prompt file is an operator error (a bad deploy, a
 * botched mount), and swallowing it would hand the user a differently shaped
 * report with no explanation.
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
