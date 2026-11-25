<?php

declare(strict_types=1);

namespace App\Services\Messaging\Sender;

/** A sender host and the one address a send connects to, checked moments before. */
final readonly class CheckedHost
{
    /** @param  bool  $public  false only for a local relay (Mailpit) in local and testing */
    public function __construct(public string $host, public string $address, public bool $public) {}
}
