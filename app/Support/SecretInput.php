<?php

declare(strict_types=1);

namespace App\Support;

/** A secret piped to a command on stdin: never an argument, never a path the server opens. */
interface SecretInput
{
    /** The one line piped in, without its line break; null when nothing is piped (a terminal). */
    public function line(): ?string;
}
