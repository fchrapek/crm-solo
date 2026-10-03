<?php

declare(strict_types=1);

namespace App\Services\Integrations\Trello;

use RuntimeException;

/**
 * A Trello API call failed. The message has been redacted by TrelloService
 * and is safe for logs, job failure records and responses.
 */
final class TrelloRequestFailed extends RuntimeException
{
    /**
     * @param  int|null  $status  the HTTP status Trello answered with, null when no answer arrived
     */
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    /** A fixed sentence for callers that show the failure to an agent: no text from Trello's answer. */
    public function summary(): string
    {
        return $this->status !== null ? "Trello answered HTTP {$this->status}." : 'Trello could not be reached.';
    }
}
