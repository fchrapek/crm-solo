<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\ClientFieldDefinition;
use App\Models\ClientFieldEvent;
use App\Models\OutboundMessage;
use App\Services\Humanizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sends due messages. Each row is claimed pending to sending with a guarded
 * update, so two runs never send one message; its recipient and text are
 * frozen at the claim; a failure is recorded and never resent on its own.
 */
final class MessageDispatcher
{
    /** A claim older than this never finished (the process died): it is reported, never sent again. */
    private const int LOST_AFTER_MINUTES = 15;

    public function __construct(
        private readonly SmsProviderRegistry $providers,
        private readonly MessageRenderer $renderer,
    ) {}

    /** @return array<string, int> status => count for this run */
    public function dispatchDue(int $limit = 100): array
    {
        $counts = [];
        $lost = OutboundMessage::query()->where('status', OutboundMessage::SENDING)->where('claimed_at', '<', now()->subMinutes(self::LOST_AFTER_MINUTES))
            ->update(['status' => OutboundMessage::FAILED, 'error' => 'lost: the sending run stopped before recording the outcome']);
        if ($lost > 0) {
            Log::warning('SMS claims lost', ['count' => $lost]);
            $counts[OutboundMessage::FAILED] = $lost;
        }
        $due = OutboundMessage::query()
            ->where('status', OutboundMessage::PENDING)
            ->where('scheduled_for', '<=', now())
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($due as $id) {
            // The schedule is rechecked in the claim itself: a reschedule between the select and here wins.
            $claimed = OutboundMessage::query()->whereKey($id)->where('status', OutboundMessage::PENDING)->where('scheduled_for', '<=', now())
                ->update(['status' => OutboundMessage::SENDING, 'claimed_at' => now()]);
            if ($claimed !== 1) {
                continue;
            }

            $status = $this->deliver(OutboundMessage::query()->with(['client.account', 'client.assignee', 'rule.template'])->findOrFail($id));
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    private function deliver(OutboundMessage $message): string
    {
        $client = $message->client;
        $template = $message->rule?->template;

        $skip = match (true) {
            $message->expires_at->isPast() => 'expired',
            $client === null || $client->trashed() => 'client_archived',
            $message->rule === null || ! $message->rule->enabled || $template === null => 'rule_removed',
            $this->fieldArchived($message) => 'field_archived',
            $this->stale($message) => 'stale',
            $client->sms_opt_out => 'opted_out',
            PhoneNumber::e164($client->phone) === null => 'no_phone',
            default => null,
        };
        if ($skip !== null) {
            return $this->finish($message, OutboundMessage::SKIPPED, ['error' => $skip]);
        }

        // Due before quiet hours began but reached after: back to the queue for the morning, unless that is too late.
        $allowed = QuietHours::adjust($client->account, CarbonImmutable::now());
        if ($allowed->isFuture()) {
            return $allowed->greaterThan($message->expires_at)
                ? $this->finish($message, OutboundMessage::SKIPPED, ['error' => 'expired'])
                : $this->finish($message, OutboundMessage::PENDING, ['scheduled_for' => $allowed, 'claimed_at' => null]);
        }

        try {
            $body = SmsText::prepare($client->account, (string) Humanizer::clean($this->renderer->render((string) $template->body, $client)));
        } catch (RuntimeException $e) {
            return $this->finish($message, OutboundMessage::SKIPPED, ['error' => mb_substr($e->getMessage(), 0, 255)]);
        }

        $to = (string) PhoneNumber::e164($client->phone);
        $provider = $this->providers->for($client->account);
        $message->forceFill(['to' => $to, 'body' => $body, 'provider' => $provider->key()])->save();

        try {
            $result = $provider->send($client->account, $to, $body, (string) $message->id);
        } catch (Throwable $e) {
            Log::warning('SMS send failed', ['message_id' => $message->id, 'provider' => $provider->key(), 'error' => $e->getMessage()]);

            return $this->finish($message, OutboundMessage::FAILED, ['error' => mb_substr($e->getMessage(), 0, 255)]);
        }

        return $this->finish($message, $result->status, [
            'provider_message_id' => $result->providerMessageId,
            'error' => $result->error !== null ? mb_substr($result->error, 0, 255) : null,
            'sent_at' => $result->status === OutboundMessage::FAILED ? null : now(),
        ]);
    }

    private function fieldArchived(OutboundMessage $message): bool
    {
        return ClientFieldDefinition::query()->where('account_id', $message->account_id)->where('key', $message->rule?->field())->whereNotNull('archived_at')->exists();
    }

    /** The value the message was queued for is no longer the field's value (it moved after the claim). */
    private function stale(OutboundMessage $message): bool
    {
        $current = $message->client?->custom_fields[$message->rule?->field()] ?? null;

        if (str_starts_with($message->occurrence, 'at:')) {
            return $current !== mb_substr($message->occurrence, 3);
        }
        if (str_starts_with($message->occurrence, 'event:')) {
            return ClientFieldEvent::query()->find((int) mb_substr($message->occurrence, 6))?->new_value !== $current;
        }

        return false;
    }

    /** @param array<string, mixed> $attributes */
    private function finish(OutboundMessage $message, string $status, array $attributes): string
    {
        $message->forceFill(['status' => $status, ...$attributes])->save();

        return $status;
    }
}
