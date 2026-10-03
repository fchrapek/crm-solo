<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\HostExecDisabledException;

/**
 * The one switch for everything that runs a process on the host: terminal
 * sessions, task previews, repository git calls and the daily-session viewport.
 * Off whenever DEMO_MODE is on, whatever CRM_HOST_EXEC says.
 */
final class HostExec
{
    public static function enabled(): bool
    {
        return (bool) config('terminal.host_exec', true) && ! config('app.demo');
    }

    public static function ensureEnabled(): void
    {
        if (! self::enabled()) {
            throw new HostExecDisabledException;
        }
    }
}
