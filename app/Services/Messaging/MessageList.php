<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\OutboundMessage;
use App\Support\LocalCalendar;

/** The account's messages, newest first, for the verb, the tool and later the screen. */
final class MessageList
{
    /** @return list<array<string, mixed>> */
    public function for(int $accountId, ?int $clientId = null, ?string $status = null, int $limit = 50): array
    {
        return OutboundMessage::query()
            ->where('account_id', $accountId)
            ->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->with(['client:id,name', 'rule:id,key'])
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->map(fn (OutboundMessage $message): array => [
                'id' => $message->id,
                'client_id' => $message->client_id,
                'client' => $message->client?->name,
                'rule' => $message->rule?->key,
                'status' => $message->status,
                'scheduled_for' => $message->scheduled_for->setTimezone(LocalCalendar::timezone())->format('Y-m-d H:i'),
                'to' => $message->to,
                'body' => $message->body,
                'error' => $message->error,
                'resends' => $message->resends,
                'previous_error' => $message->previous_error,
                'untrusted' => ['client', 'body'],
            ])->values()->all();
    }
}
