<?php

declare(strict_types=1);

namespace App\Services\Messaging\Sender;

interface HostResolver
{
    /**
     * Every A and AAAA answer for the host, empty when it does not resolve.
     *
     * @return list<string>
     */
    public function addresses(string $host): array;
}
