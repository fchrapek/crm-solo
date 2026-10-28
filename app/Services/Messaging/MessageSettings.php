<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientFieldDefinition;
use App\Models\MessageRule;
use App\Models\MessageTemplate;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Agent\AgentRefusal;
use App\Services\Humanizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** The one place an account's message templates and rules change, and a queued message is cancelled or a failed one sent again. */
final class MessageSettings
{
    public function __construct(
        private readonly MessageRenderer $renderer,
        private readonly MessageScheduler $scheduler,
    ) {}

    public function template(int $accountId, string $key, ?string $name, ?string $body): MessageTemplate
    {
        return DB::transaction(fn (): MessageTemplate => $this->templateLocked($accountId, $key, $name, $body));
    }

    /**
     * @param  array{template?: ?string, trigger?: ?string, field?: ?string, values?: ?list<string>, offset_minutes?: ?int, at?: ?string, expire_minutes?: ?int, enabled?: ?bool}  $attributes
     */
    public function rule(int $accountId, string $key, array $attributes): MessageRule
    {
        $rule = DB::transaction(fn (): MessageRule => $this->ruleLocked($accountId, $key, $attributes));
        $this->scheduler->recomputeAccount($accountId);

        return $rule;
    }

    /** A template a rule still uses stays. */
    public function deleteTemplate(int $accountId, string $key): void
    {
        DB::transaction(function () use ($accountId, $key): void {
            $template = MessageTemplate::query()->where('account_id', $accountId)->where('key', $key)->lockForUpdate()->first()
                ?? throw AgentRefusal::invalid("No template {$key}.");
            $rules = MessageRule::query()->where('template_id', $template->id)->pluck('key')->all();
            if ($rules !== []) {
                throw AgentRefusal::invalid("Template {$key} is used by the rule ".implode(', ', $rules).'; delete or change that rule first.', ['rules' => $rules]);
            }
            $template->delete();
        });
    }

    /** Its waiting messages are withdrawn; sent ones stay in the history without the rule. */
    public function deleteRule(int $accountId, string $key): void
    {
        DB::transaction(function () use ($accountId, $key): void {
            $rule = MessageRule::query()->where('account_id', $accountId)->where('key', $key)->lockForUpdate()->first()
                ?? throw AgentRefusal::invalid("No rule {$key}.");
            OutboundMessage::query()->where('rule_id', $rule->id)->where('status', OutboundMessage::PENDING)
                ->update(['status' => OutboundMessage::SUPERSEDED]);
            $rule->delete();
        });
    }

    /**
     * Quiet hours (local HH:MM), whether Polish letters are spelled without diacritics, and the provider that sends.
     *
     * @return array{quiet_from: string, quiet_to: string, ascii: bool, provider: string}
     */
    public function sending(int $accountId, ?string $quietFrom = null, ?string $quietTo = null, ?bool $ascii = null, ?string $provider = null): array
    {
        $providers = app(SmsProviderRegistry::class)->keys();
        if ($provider !== null && ! in_array($provider, $providers, true)) {
            throw AgentRefusal::invalid("Unknown SMS provider {$provider}.", ['valid_providers' => $providers]);
        }

        foreach ([$quietFrom, $quietTo] as $time) {
            if ($time !== null && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                throw AgentRefusal::invalid('Quiet hours are local times as HH:MM.');
            }
        }

        // A waiting message is not rescheduled: the dispatcher reads the window when it sends, so a message due inside the new hours waits for their end.
        DB::transaction(function () use ($accountId, $quietFrom, $quietTo, $ascii, $provider): void {
            $account = Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
            if ($provider === 'smsapi' && SmsapiProvider::connected($account) === null) {
                throw AgentRefusal::invalid('Connect SMSAPI in Settings, Integrations first: add the token and turn it on.');
            }
            $settings = (array) $account->settings;
            $messages = (array) ($settings['messages'] ?? []);
            if ($quietFrom !== null || $quietTo !== null) {
                [$from, $to] = QuietHours::window($account);
                $messages['quiet_hours'] = ['from' => $quietFrom ?? $from, 'to' => $quietTo ?? $to];
            }
            if ($ascii !== null) {
                $messages['ascii'] = $ascii;
            }
            if ($provider !== null) {
                $messages['provider'] = $provider;
            }
            $account->settings = [...$settings, 'messages' => $messages];
            $account->save();
        });

        return $this->sendingOf(Account::query()->findOrFail($accountId));
    }

    /** @return array{quiet_from: string, quiet_to: string, ascii: bool, provider: string} */
    public function sendingOf(Account $account): array
    {
        [$from, $to] = QuietHours::window($account);

        return ['quiet_from' => $from, 'quiet_to' => $to, 'ascii' => SmsText::ascii($account), 'provider' => app(SmsProviderRegistry::class)->for($account)->key()];
    }

    /**
     * The text as a client would get it, filled from one of the account's clients
     * (a placeholder that client has no value for stays as written).
     *
     * @return array{text: string, client: ?string, characters: int, segments: int, unicode: bool}
     */
    public function preview(int $accountId, string $body, ?Client $client = null): array
    {
        $this->renderer->assertValid($accountId, $body);
        $account = Account::query()->findOrFail($accountId);
        $client ??= Client::query()->where('account_id', $accountId)->whereNotNull('custom_fields')->latest('updated_at')->first()
            ?? Client::query()->where('account_id', $accountId)->first();

        $text = $client === null ? $body : $this->renderer->render($body, $client, strict: false);
        $text = SmsText::prepare($account, (string) Humanizer::clean($text));

        return ['text' => $text, 'client' => $client?->name, ...SmsText::measure($text)];
    }

    public function cancel(int $accountId, int $messageId, ?User $actor): OutboundMessage
    {
        $message = OutboundMessage::query()->where('account_id', $accountId)->find($messageId)
            ?? throw AgentRefusal::invalid("No message {$messageId}.");

        $cancelled = OutboundMessage::query()->whereKey($message->id)->whereIn('status', [OutboundMessage::PENDING, OutboundMessage::FAILED])
            ->update(['status' => OutboundMessage::CANCELLED, 'cancelled_by' => $actor?->id]);
        if ($cancelled !== 1) {
            throw AgentRefusal::invalid("Message {$messageId} is {$message->fresh()?->status}; only a pending or failed message can be cancelled.");
        }

        return $message->fresh();
    }

    /**
     * A failed message goes back to the queue as a fresh copy: the next dispatch
     * rebuilds recipient and text from the client as they are then, under the
     * usual quiet hours and opt-out. The failure stays as previous_error.
     */
    public function resend(int $accountId, int $messageId): OutboundMessage
    {
        $message = OutboundMessage::query()->where('account_id', $accountId)->with(['rule.template', 'client.account'])->find($messageId)
            ?? throw AgentRefusal::invalid("No message {$messageId}.");
        if ($message->status !== OutboundMessage::FAILED) {
            throw AgentRefusal::invalid("Message {$messageId} is {$message->status}; only a failed message can be sent again.");
        }
        if ($message->client === null) {
            throw AgentRefusal::invalid("The client of message {$messageId} is archived.");
        }
        if ($message->rule === null || ! $message->rule->enabled || $message->rule->template === null) {
            throw AgentRefusal::invalid("The rule behind message {$messageId} is gone or off; turn it on before sending again.");
        }

        $now = CarbonImmutable::now();
        $resent = OutboundMessage::query()->whereKey($message->id)->where('status', OutboundMessage::FAILED)->update([
            'status' => OutboundMessage::PENDING,
            'scheduled_for' => $now,
            'expires_at' => QuietHours::adjust($message->client->account, $now)->addMinutes($message->rule->expire_minutes),
            'previous_error' => $message->error,
            'error' => null,
            'resends' => $message->resends + 1,
            'to' => null,
            'body' => null,
            'provider' => null,
            'provider_message_id' => null,
            'claimed_at' => null,
            'sent_at' => null,
        ]);
        if ($resent !== 1) {
            throw AgentRefusal::invalid("Message {$messageId} is {$message->fresh()?->status}; only a failed message can be sent again.");
        }

        return $message->fresh();
    }

    public function optOut(Client $client, bool $optOut): void
    {
        DB::transaction(function () use ($client, $optOut): void {
            $locked = Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['sms_opt_out' => $optOut])->save();
            $client->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function templateLocked(int $accountId, string $key, ?string $name, ?string $body): MessageTemplate
    {
        $key = $this->key($key);
        $template = MessageTemplate::query()->where('account_id', $accountId)->where('key', $key)->lockForUpdate()->first()
            ?? new MessageTemplate(['account_id' => $accountId, 'key' => $key]);

        if ($body !== null && mb_trim($body) !== '') {
            $this->renderer->assertValid($accountId, $body);
            $template->body = mb_trim($body);
        }
        if ($name !== null && mb_trim($name) !== '') {
            $template->name = mb_substr(mb_trim($name), 0, 100);
        }
        if (! $template->exists && ($template->body === null || $template->name === null)) {
            throw AgentRefusal::invalid("Pass a name and a body for the new template {$key}.");
        }

        $template->save();

        return $template;
    }

    /** @param array<string, mixed> $attributes */
    private function ruleLocked(int $accountId, string $key, array $attributes): MessageRule
    {
        $key = $this->key($key);
        $rule = MessageRule::query()->where('account_id', $accountId)->where('key', $key)->lockForUpdate()->first()
            ?? new MessageRule(['account_id' => $accountId, 'key' => $key, 'trigger_config' => []]);

        if (($attributes['template'] ?? null) !== null) {
            $template = MessageTemplate::query()->where('account_id', $accountId)->where('key', $attributes['template'])->first()
                ?? throw AgentRefusal::invalid("No template {$attributes['template']}.", ['valid_templates' => MessageTemplate::query()->where('account_id', $accountId)->pluck('key')->all()]);
            $rule->template_id = $template->id;
        }

        $trigger = $attributes['trigger'] ?? $rule->trigger_type;
        if (! in_array($trigger, [MessageRule::FIELD_VALUE, MessageRule::DATE_OFFSET], true)) {
            throw AgentRefusal::invalid('Pass the trigger: field_value or date_offset.');
        }
        if (! $rule->exists && ($attributes['expire_minutes'] ?? null) === null) {
            // A confirmation queued late in the day still goes out the next morning.
            $rule->expire_minutes = $trigger === MessageRule::FIELD_VALUE ? 900 : 120;
        }
        $config = $trigger === $rule->trigger_type ? (array) $rule->trigger_config : [];
        $rule->trigger_type = $trigger;

        foreach (['field', 'values', 'offset_minutes', 'at'] as $name) {
            if (array_key_exists($name, $attributes) && $attributes[$name] !== null) {
                $config[$name] = $attributes[$name];
            }
        }
        $rule->trigger_config = $this->checkedConfig($accountId, $trigger, $config);

        if (($attributes['expire_minutes'] ?? null) !== null) {
            $rule->expire_minutes = max(1, (int) $attributes['expire_minutes']);
        }
        if (($attributes['enabled'] ?? null) !== null) {
            $rule->enabled = (bool) $attributes['enabled'];
        }
        if ($rule->template_id === null) {
            throw AgentRefusal::invalid("Pass the template for the new rule {$key}.");
        }

        $rule->save();

        return $rule;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function checkedConfig(int $accountId, string $trigger, array $config): array
    {
        $field = ClientFieldDefinition::query()->where('account_id', $accountId)->where('key', (string) ($config['field'] ?? ''))->active()->first()
            ?? throw AgentRefusal::invalid('Pass the field the rule watches.', ['valid_fields' => ClientFieldDefinition::query()->where('account_id', $accountId)->active()->pluck('key')->all()]);

        if ($trigger === MessageRule::DATE_OFFSET) {
            if (! in_array($field->type, ['date', 'datetime'], true)) {
                throw AgentRefusal::invalid("A date rule needs a date or datetime field; {$field->key} is {$field->type}.");
            }
            $at = ($config['at'] ?? null) ?: null;
            if ($at !== null && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $at)) {
                throw AgentRefusal::invalid('at is a local time as HH:MM.');
            }

            return ['field' => $field->key, 'offset_minutes' => (int) ($config['offset_minutes'] ?? 0), 'at' => $at];
        }

        $values = array_values(array_filter((array) ($config['values'] ?? []), 'is_string'));
        if ($values !== [] && $field->hasOptions()) {
            $unknown = array_diff($values, array_keys($field->optionLabels()));
            if ($unknown !== []) {
                throw AgentRefusal::invalid('Unknown option '.implode(', ', $unknown)." of {$field->key}.", ['valid_options' => array_keys($field->optionLabels())]);
            }
        }

        return ['field' => $field->key, 'values' => $values === [] ? null : $values];
    }

    private function key(string $key): string
    {
        $key = mb_trim($key);
        if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
            throw AgentRefusal::invalid("Key {$key}: lowercase letters, digits and _, starting with a letter.");
        }

        return $key;
    }
}
