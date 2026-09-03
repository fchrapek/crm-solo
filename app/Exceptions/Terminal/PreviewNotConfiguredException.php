<?php

declare(strict_types=1);

namespace App\Exceptions\Terminal;

final class PreviewNotConfiguredException extends TerminalActionException
{
    public function errorCode(): string
    {
        return 'preview_not_configured';
    }
}
