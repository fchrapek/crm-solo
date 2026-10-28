<?php

declare(strict_types=1);

namespace App\Services\Clients;

enum SettlementOutcome: string
{
    case Applied = 'applied';
    case Unchanged = 'unchanged';
    case Refused = 'refused';
}
