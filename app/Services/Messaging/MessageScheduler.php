<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\Client;
use App\Models\ClientFieldDefinition;
use App\Models\MessageRule;
use App\Models\OutboundMessage;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a client's pending messages in step with its fields. Runs inside the
 * caller's transaction with the client row locked, and only ever changes rows
 * still `pending`, so a message the dispatcher claimed is never touched.
 */
final class MessageScheduler
{
    /**
     * @param  list<array{key: string, old: mixed, new: mixed, event_id: int}>  $changes  from ClientFieldWriter
     */
    public function fieldsChanged(Client $client, array $changes): void
    {
        $rules = $this->rules($client->account_id);

        foreach ($rules->where('trigger_type', MessageRule::FIELD_VALUE) as $rule) {
            foreach ($changes as $change) {
                if ($change['key'] !== $rule->field()) {
                    continue;
                }

                // Any change of the watched field replaces what waits; only a matching value queues a new one.
                $this->supersedePending($rule, $client);
                if (! $this->matches($rule, $change['new'])) {
                    continue;
                }

                $at = QuietHours::adjust($client->account, CarbonImmutable::now());
                OutboundMessage::create([
                    'account_id' => $client->account_id,
                    'client_id' => $client->id,
                    'rule_id' => $rule->id,
                    'occurrence' => 'event:'.$change['event_id'],
                    'status' => OutboundMessage::PENDING,
                    'scheduled_for' => $at,
                    'expires_at' => $at->addMinutes($rule->expire_minutes),
                ]);
            }
        }

        $this->recompute($client);
    }

    /** Re-derives the pending reminders of every date rule for one client. */
    public function recompute(Client $client): void
    {
        foreach ($this->rules($client->account_id, withDisabled: true)->where('trigger_type', MessageRule::DATE_OFFSET) as $rule) {
            $sendAt = $rule->enabled ? $this->dateSendAt($rule, $client) : null;
            $occurrence = $sendAt !== null ? 'at:'.$client->custom_fields[$rule->field()] : null;

            OutboundMessage::query()
                ->where('rule_id', $rule->id)
                ->where('client_id', $client->id)
                ->where('status', OutboundMessage::PENDING)
                ->when($occurrence !== null, fn ($query) => $query->where('occurrence', '!=', $occurrence))
                ->update(['status' => OutboundMessage::SUPERSEDED]);

            if ($sendAt === null || $sendAt->isPast()) {
                // A rule change that moves the send time into the past withdraws the reminder. One merely due, or
                // deferred by quiet hours, keeps its expiry (a deferral never moves it), so it stays; so does one sent again by hand.
                if ($occurrence !== null) {
                    OutboundMessage::query()->where('rule_id', $rule->id)->where('client_id', $client->id)
                        ->where('occurrence', $occurrence)->where('status', OutboundMessage::PENDING)->where('resends', 0)
                        ->where('expires_at', '!=', QuietHours::adjust($client->account, $sendAt)->addMinutes($rule->expire_minutes))
                        ->update(['status' => OutboundMessage::SUPERSEDED]);
                }

                continue;
            }

            $at = QuietHours::adjust($client->account, $sendAt);
            $schedule = ['scheduled_for' => $at, 'expires_at' => $at->addMinutes($rule->expire_minutes)];
            $existing = OutboundMessage::query()->where('rule_id', $rule->id)->where('client_id', $client->id)->where('occurrence', $occurrence)->first();

            if ($existing === null) {
                OutboundMessage::create([
                    'account_id' => $client->account_id,
                    'client_id' => $client->id,
                    'rule_id' => $rule->id,
                    'occurrence' => $occurrence,
                    'status' => OutboundMessage::PENDING,
                    ...$schedule,
                ]);
            } elseif (in_array($existing->status, [OutboundMessage::PENDING, OutboundMessage::SUPERSEDED], true)) {
                // A date moved back to an earlier value revives its reminder; cancelled and sent rows stay as they are.
                OutboundMessage::query()->whereKey($existing->id)->whereIn('status', [OutboundMessage::PENDING, OutboundMessage::SUPERSEDED])
                    ->update(['status' => OutboundMessage::PENDING, ...$schedule]);
            }
        }
    }

    /** After a rule changes: every client of the account, each under its own row lock. */
    public function recomputeAccount(int $accountId): void
    {
        Client::query()->where('account_id', $accountId)->whereNotNull('custom_fields')->pluck('id')
            ->each(fn (int $id) => DB::transaction(function () use ($id): void {
                $client = Client::query()->whereKey($id)->lockForUpdate()->first();
                if ($client !== null) {
                    $this->recompute($client);
                }
            }));
    }

    private function dateSendAt(MessageRule $rule, Client $client): ?CarbonImmutable
    {
        $value = $client->custom_fields[$rule->field()] ?? null;
        $field = ClientFieldDefinition::query()->where('account_id', $client->account_id)->where('key', $rule->field())->first();
        if (! is_string($value) || $field === null || $field->archived_at !== null || ! in_array($field->type, ['date', 'datetime'], true)) {
            return null;
        }

        $local = $field->type === 'date'
            ? CarbonImmutable::createFromFormat('!Y-m-d', $value, LocalCalendar::timezone())
            : CarbonImmutable::parse($value)->setTimezone(LocalCalendar::timezone());
        // Whole days count on the wall clock, so "two days before 09:00" stays 09:00 across a DST change.
        $offset = (int) ($rule->trigger_config['offset_minutes'] ?? 0);
        $local = $local->addDays(intdiv($offset, 1440))->addMinutes($offset % 1440);

        $at = $rule->trigger_config['at'] ?? null;
        if (is_string($at) && preg_match('/^(\d{2}):(\d{2})$/', $at, $m)) {
            $local = $local->setTime((int) $m[1], (int) $m[2]);
        }

        return $local->utc();
    }

    private function matches(MessageRule $rule, mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        $values = $rule->trigger_config['values'] ?? null;
        if (! is_array($values) || $values === []) {
            return true;
        }

        return array_intersect((array) $value, $values) !== [];
    }

    private function supersedePending(MessageRule $rule, Client $client): void
    {
        OutboundMessage::query()
            ->where('rule_id', $rule->id)
            ->where('client_id', $client->id)
            ->where('status', OutboundMessage::PENDING)
            ->update(['status' => OutboundMessage::SUPERSEDED]);
    }

    /** @return \Illuminate\Support\Collection<int, MessageRule> */
    private function rules(int $accountId, bool $withDisabled = false): \Illuminate\Support\Collection
    {
        return MessageRule::query()
            ->where('account_id', $accountId)
            ->when(! $withDisabled, fn ($query) => $query->where('enabled', true))
            ->orderBy('id')
            ->get();
    }
}
