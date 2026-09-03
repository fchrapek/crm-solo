<?php

declare(strict_types=1);

namespace App\Exceptions\Terminal;

final class WorkingTreeDirtyException extends TerminalActionException
{
    public function errorCode(): string
    {
        return 'working_tree_dirty';
    }
}
