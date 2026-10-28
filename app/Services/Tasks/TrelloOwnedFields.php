<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use RuntimeException;

/** A change to what a Trello card's board owns, refused: the card is edited on Trello. */
final class TrelloOwnedFields extends RuntimeException
{
    /**
     * @param  list<string>  $fields
     */
    public function __construct(public readonly int $taskId, public readonly array $fields)
    {
        parent::__construct("Task #{$taskId} is a Trello card: change its ".implode(', ', $fields).' on the Trello board.');
    }
}
