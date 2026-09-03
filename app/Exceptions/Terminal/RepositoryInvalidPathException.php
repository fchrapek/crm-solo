<?php

declare(strict_types=1);

namespace App\Exceptions\Terminal;

final class RepositoryInvalidPathException extends TerminalActionException
{
    public function errorCode(): string
    {
        return 'repository_invalid_path';
    }
}
