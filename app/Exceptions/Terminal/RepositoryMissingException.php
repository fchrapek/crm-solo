<?php

declare(strict_types=1);

namespace App\Exceptions\Terminal;

final class RepositoryMissingException extends TerminalActionException
{
    public function errorCode(): string
    {
        return 'repository_missing';
    }
}
