<?php

declare(strict_types=1);

namespace App\Exceptions\Terminal;

final class RepoBusyException extends TerminalActionException
{
    public function errorCode(): string
    {
        return 'repo_busy';
    }
}
