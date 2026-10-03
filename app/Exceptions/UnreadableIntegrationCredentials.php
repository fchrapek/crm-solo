<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A stored integration credential is encrypted under another APP_KEY. Using
 * it would send a wrong credential, so the integration refuses to run.
 */
final class UnreadableIntegrationCredentials extends RuntimeException
{
    /**
     * @param  list<string>  $fields
     */
    public function __construct(string $provider, array $fields)
    {
        parent::__construct(sprintf(
            'The stored %s credentials (%s) cannot be decrypted with the current APP_KEY. Restore the APP_KEY they were saved with, or enter them again on the Integrations page.',
            $provider,
            implode(', ', $fields),
        ));
    }
}
