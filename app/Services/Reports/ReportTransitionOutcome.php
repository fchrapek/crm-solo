<?php

declare(strict_types=1);

namespace App\Services\Reports;

enum ReportTransitionOutcome: string
{
    case Applied = 'applied';
    case Unchanged = 'unchanged';
    case Refused = 'refused';
}
