<?php

declare(strict_types=1);

namespace App\Exceptions\Terminal;

final class SessionLostException extends TerminalActionException
{
    public function errorCode(): string
    {
        return 'session_lost';
    }
}
