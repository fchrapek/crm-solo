<?php

declare(strict_types=1);

namespace App\Services\Messaging\Sender;

use Symfony\Component\Mailer\Transport\TransportInterface;

interface SenderTransportFactory
{
    public function make(SenderConfig $sender, CheckedHost $host): TransportInterface;
}
