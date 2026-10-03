<?php

declare(strict_types=1);

namespace App\Services\Integrations\Trello;

use RuntimeException;

/** Another sync of the same board holds its lock; this one did nothing. */
final class TrelloBoardSyncRunning extends RuntimeException {}
