<?php

declare(strict_types=1);

namespace App\Exceptions\Terminal;

final class WorktreeMissingException extends TerminalActionException
{
    public function errorCode(): string
    {
        return 'worktree_missing';
    }
}
