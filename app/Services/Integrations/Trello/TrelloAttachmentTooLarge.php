<?php

declare(strict_types=1);

namespace App\Services\Integrations\Trello;

use RuntimeException;

/**
 * A card attachment's body passed the size limit while streaming; the
 * transfer was stopped and the caller discards the partial file.
 */
final class TrelloAttachmentTooLarge extends RuntimeException {}
