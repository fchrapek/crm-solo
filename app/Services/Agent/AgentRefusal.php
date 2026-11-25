<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Services\Time\PeriodSettled;
use RuntimeException;

/**
 * A write the agent asked for and the CRM refused, as data: an error code the
 * model can branch on, a message, and context such as the valid values or the
 * records that caused it. The CLI prints it, MCP returns it as the error payload.
 */
final class AgentRefusal extends RuntimeException
{
    public const string INVALID_ARGUMENT = 'invalid_argument';

    public const string TRELLO_OWNED = 'trello_owned';

    public const string POSSIBLE_DUPLICATE = 'possible_duplicate';

    public const string PERIOD_SETTLED = 'period_settled';

    public const string FEATURE_OFF = 'feature_off';

    public const string REPORT_EXISTS = 'report_exists';

    public const string NOT_A_DRAFT = 'not_a_draft';

    /** The account has no enabled Trello integration holding both keys. */
    public const string TRELLO_NOT_CONNECTED = 'trello_not_connected';

    /** The project already has a Trello board. */
    public const string ALREADY_LINKED = 'already_linked';

    /** Another client's project holds the board. */
    public const string BOARD_LINKED_ELSEWHERE = 'board_linked_elsewhere';

    /** Trello did not answer, or answered with an error. */
    public const string TRELLO_FAILED = 'trello_failed';

    /** The UI's own service said no; the message says why, as the page would. */
    public const string REFUSED = 'refused';

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $error,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function invalid(string $message, array $context = []): self
    {
        return new self(self::INVALID_ARGUMENT, $message, $context);
    }

    public static function featureOff(string $feature): self
    {
        return new self(self::FEATURE_OFF, "The {$feature} module is switched off for this account.", ['feature' => $feature]);
    }

    public static function periodSettled(PeriodSettled $settled): self
    {
        return new self(self::PERIOD_SETTLED, TimeEntryRecord::lockedReason(['reason' => $settled->reason, 'month' => $settled->month]), [
            'reason' => $settled->reason,
            'month' => $settled->month,
            'client_id' => $settled->clientId,
            'day' => $settled->day,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'error' => $this->error,
            'message' => $this->getMessage(),
            ...$this->context,
        ];
    }
}
