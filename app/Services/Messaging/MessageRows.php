<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\ClientFieldDefinition;
use App\Models\OutboundMessage;
use App\Services\Humanizer;
use App\Support\LocalCalendar;
use Illuminate\Support\Collection;
use Throwable;

/**
 * One text message as the screens show it. A message not yet sent has no
 * frozen text, so its text is the template filled in now (what it would say).
 */
final class MessageRows
{
    /** @var array<int, Collection<string, ClientFieldDefinition>> active definitions by account, read once per list */
    private array $fields = [];

    public function __construct(private readonly MessageRenderer $renderer) {}

    /** @return array<string, mixed> */
    public function row(OutboundMessage $message): array
    {
        $at = ($message->sent_at ?? $message->scheduled_for)->copy()->setTimezone(LocalCalendar::timezone());

        return [
            'id' => $message->id,
            'client_id' => $message->client_id,
            'client' => $message->client?->name,
            'day' => $at->toDateString(),
            'time' => $at->format('H:i'),
            'status' => $message->status,
            // Still waiting, but the client gets no texts: the dispatcher will skip it unless they are switched back on in time.
            'held' => $message->status === OutboundMessage::PENDING && (bool) $message->client?->sms_opt_out,
            'template' => $message->rule?->template?->name,
            'body' => $message->body ?? $this->preview($message),
            'to' => $message->to ?? ($message->client === null ? null : PhoneNumber::e164($message->client->phone)),
            'error' => $message->error,
            'may_have_gone_out' => $message->status === OutboundMessage::FAILED && OutboundMessage::mayHaveGoneOut($message->error),
            'resends' => $message->resends,
            'previous_error' => $message->previous_error,
            'expires' => $message->expires_at->copy()->setTimezone(LocalCalendar::timezone())->format('Y-m-d H:i'),
            'untrusted' => ['client', 'body', 'template'],
        ];
    }

    private function preview(OutboundMessage $message): ?string
    {
        $body = $message->rule?->template?->body;
        if ($body === null || $message->client === null) {
            return null;
        }

        try {
            $fields = $this->fields[$message->account_id] ??= $this->renderer->fields($message->account_id);

            return SmsText::prepare($message->client->account, (string) Humanizer::clean($this->renderer->render((string) $body, $message->client, strict: false, fields: $fields)));
        } catch (Throwable) {
            return null;
        }
    }
}
