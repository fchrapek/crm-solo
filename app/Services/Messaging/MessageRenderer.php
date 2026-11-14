<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\Client;
use App\Models\ClientFieldDefinition;
use App\Models\MessageTemplate;
use App\Services\Agent\AgentRefusal;
use App\Services\Clients\Fields\FieldValue;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Fills a template: {client_name}, {assignee_name} and {field:key}, and in an
 * email also {period}, {work_list} and {hours_summary} of the month it is
 * about. A placeholder without a value stops the message instead of sending a gap.
 */
final class MessageRenderer
{
    private const string PATTERN = '/\{([^{}]*)\}/';

    public function __construct(private readonly MonthPlaceholders $month) {}

    /**
     * Refuses a text with a placeholder the account cannot fill on that channel.
     */
    public function assertValid(int $accountId, string $body, string $channel = MessageTemplate::SMS, bool $subject = false): void
    {
        $keys = ClientFieldDefinition::query()->where('account_id', $accountId)->active()->pluck('key')->all();
        $email = $channel === MessageTemplate::EMAIL;
        preg_match_all(self::PATTERN, $body, $found);
        foreach ($found[1] as $token) {
            $ok = in_array($token, ['client_name', 'assignee_name'], true)
                || ($email && in_array($token, MonthPlaceholders::TOKENS, true))
                || (str_starts_with($token, 'field:') && in_array(mb_substr($token, 6), $keys, true));
            if ($ok && $subject && in_array($token, MonthPlaceholders::MULTILINE, true)) {
                throw AgentRefusal::invalid('The work list and the hours run over several lines; put them in the text, not the subject.', ['field' => 'subject', 'placeholder' => $token]);
            }
            if (! $ok) {
                $valid = $email ? '{client_name}, {assignee_name}, {period}, {work_list}, {hours_summary} or {field:key}' : '{client_name}, {assignee_name} or {field:key}';

                throw AgentRefusal::invalid("Unknown placeholder {{$token}}. Use {$valid}.", ['valid_fields' => $keys]);
            }
        }
    }

    /**
     * A preview passes $strict false: a placeholder without a value stays as written. A list passes the
     * account's fields() once instead of a query per message.
     *
     * @param  Collection<string, ClientFieldDefinition>|null  $fields
     * @param  string|null  $period  the month (YYYY-MM) an email is about; without it the month placeholders have no value
     *
     * @throws RuntimeException naming the placeholder without a value
     */
    public function render(string $body, Client $client, bool $strict = true, ?Collection $fields = null, ?string $period = null): string
    {
        $fields ??= $this->fields($client->account_id);
        $monthValues = [];

        return (string) preg_replace_callback(self::PATTERN, function (array $match) use ($client, $fields, $strict, $period, &$monthValues): string {
            $token = $match[1];
            $value = match (true) {
                $token === 'client_name' => $client->name,
                $token === 'assignee_name' => $client->assignee?->name,
                str_starts_with($token, 'field:') && $fields->has(mb_substr($token, 6)) => FieldValue::readable($fields[mb_substr($token, 6)], $client->custom_fields[mb_substr($token, 6)] ?? null),
                $period !== null && in_array($token, MonthPlaceholders::TOKENS, true) => $monthValues[$token] ??= $this->month->value($token, $client, $period),
                default => null,
            };
            if ($value === '' && $period !== null && in_array($token, MonthPlaceholders::MAY_BE_EMPTY, true)) {
                return '';
            }
            if (($value === null || $value === '') && ! $strict) {
                return $match[0];
            }
            if ($value === null || $value === '') {
                throw new RuntimeException("missing {{$token}}");
            }

            return $value;
        }, $body);
    }

    /**
     * Archived fields keep their values but no longer speak to clients.
     *
     * @return Collection<string, ClientFieldDefinition>
     */
    public function fields(int $accountId): Collection
    {
        return ClientFieldDefinition::query()->where('account_id', $accountId)->active()->get()->keyBy('key');
    }
}
