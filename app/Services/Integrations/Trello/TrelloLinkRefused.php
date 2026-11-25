<?php

declare(strict_types=1);

namespace App\Services\Integrations\Trello;

use App\Models\Project;
use RuntimeException;

/** Connecting or linking a board was refused before anything changed; the message is the one the connect dialog shows. */
final class TrelloLinkRefused extends RuntimeException
{
    public const string NOT_CONFIGURED = 'not_configured';

    public const string ALREADY_LINKED = 'already_linked';

    public const string LINKED_ELSEWHERE = 'linked_elsewhere';

    public const string BOARD_NOT_FOUND = 'board_not_found';

    /**
     * @param  Project|null  $holder  the client's project that already holds the board
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?Project $holder = null,
    ) {
        parent::__construct($message);
    }
}
