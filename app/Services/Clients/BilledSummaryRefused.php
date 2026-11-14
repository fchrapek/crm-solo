<?php

declare(strict_types=1);

namespace App\Services\Clients;

use RuntimeException;

/** A billed time setting change that cannot keep a settled figure or a draft's text; thrown inside the lock so the whole change rolls back. */
final class BilledSummaryRefused extends RuntimeException {}
