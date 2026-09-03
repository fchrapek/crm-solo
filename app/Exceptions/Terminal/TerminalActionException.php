<?php

declare(strict_types=1);

namespace App\Exceptions\Terminal;

use RuntimeException;

/**
 * Launcher failures the frontend routes on. Each subclass carries a stable
 * `code` the UI switches on (repo dialog vs plain toast) — typed instead of
 * matching English message substrings, which silently degraded to generic
 * toasts whenever a message was reworded.
 */
abstract class TerminalActionException extends RuntimeException
{
    abstract public function errorCode(): string;
}
