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
use App\Services\Agent\AgentWriteRecorder;
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

    /** An email template also takes a subject; a key belongs to one channel. */
    public function template(int $accountId, string $key, ?string $name, ?string $body, string $channel = MessageTemplate::SMS, ?string $subject = null, ?bool $isDefault = null): MessageTemplate
    {
        return DB::transaction(fn (): MessageTemplate => $this->templateLocked($accountId, $key, $name, $body, $channel, $subject, $isDefault));
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

    /** A template a rule still uses stays; a summary email made from it keeps its own text. */
    public function deleteTemplate(int $accountId, string $key, string $channel = MessageTemplate::SMS): void
    {
        DB::transaction(function () use ($accountId, $key, $channel): void {
            $template = MessageTemplate::query()->where('account_id', $accountId)->where('key', $key)->where('channel', $channel)->lockForUpdate()->first()
                ?? throw AgentRefusal::invalid("No {$channel} template {$key}.");
            if ($template->is_default && MessageTemplate::query()->where('account_id', $accountId)->where('channel', $channel)->whereKeyNot($template->id)->exists()) {
                throw AgentRefusal::invalid('Set another template as the default first.');
            }
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
            if ($messages !== (array) ($settings['messages'] ?? [])) {
                app(AgentWriteRecorder::class)->recordUpdate($account, ['messages' => $settings['messages'] ?? null]);
            }
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

    /**
     * The email as the client would get it for one month, subject and body.
     * A placeholder without a value stays as written.
     *
     * @return array{subject: string, body: string, client: string, period: string, period_label: string}
     */
    public function emailPreview(int $accountId, string $subject, string $body, Client $client, string $period): array
    {
        $this->renderer->assertValid($accountId, $subject, MessageTemplate::EMAIL, subject: true);
        $this->renderer->assertValid($accountId, $body, MessageTemplate::EMAIL);
        $fields = $this->renderer->fields($accountId);

        return [
            'subject' => SummaryEmails::oneLine((string) Humanizer::clean($this->renderer->render($subject, $client, false, $fields, $period))),
            'body' => (string) Humanizer::clean($this->renderer->render($body, $client, false, $fields, $period)),
            'client' => $client->name,
            'period' => $period,
            'period_label' => app(MonthPlaceholders::class)->period($period),
        ];
    }

    /** @return list<array{key: string, name: string, subject: string, body: string, is_default: bool}> */
    public function emailTemplates(int $accountId): array
    {
        return MessageTemplate::query()->where('account_id', $accountId)->where('channel', MessageTemplate::EMAIL)->orderBy('id')->get()
            ->map(fn (MessageTemplate $template): array => [
                'key' => $template->key,
                'name' => $template->name,
                'subject' => (string) $template->subject,
                'body' => (string) $template->body,
                'is_default' => $template->is_default,
            ])->values()->all();
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

        return $this->recorded($message);
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

        return $this->recorded($message);
    }

    public function optOut(Client $client, bool $optOut): void
    {
        DB::transaction(function () use ($client, $optOut): void {
            $locked = Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['sms_opt_out' => $optOut])->save();
            $client->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** The message as stored now; its status move was a query update, so the agent trail is written here. */
    private function recorded(OutboundMessage $message): OutboundMessage
    {
        $fresh = $message->fresh();
        app(AgentWriteRecorder::class)->recordUpdate($fresh, ['status' => $message->status]);

        return $fresh;
    }

    private function templateLocked(int $accountId, string $key, ?string $name, ?string $body, string $channel, ?string $subject, ?bool $isDefault): MessageTemplate
    {
        $key = $this->key($key);
        $template = MessageTemplate::query()->where('account_id', $accountId)->where('key', $key)->lockForUpdate()->first()
            ?? new MessageTemplate(['account_id' => $accountId, 'key' => $key, 'channel' => $channel]);
        if ($template->channel !== $channel) {
            throw AgentRefusal::invalid("Template {$key} is a {$template->channel} template; pick another key.");
        }

        if ($body !== null && mb_trim($body) !== '') {
            $this->renderer->assertValid($accountId, $body, $channel);
            $template->body = mb_trim($body);
        }
        if ($name !== null && mb_trim($name) !== '') {
            $template->name = mb_substr(mb_trim($name), 0, 100);
        }
        if ($channel === MessageTemplate::EMAIL && $subject !== null && mb_trim($subject) !== '') {
            $this->renderer->assertValid($accountId, $subject, $channel, subject: true);
            $template->subject = SummaryEmails::oneLine($subject);
        }
        if (! $template->exists && ($template->body === null || $template->name === null)) {
            throw AgentRefusal::invalid("Pass a name and a body for the new template {$key}.");
        }
        if ($channel === MessageTemplate::EMAIL && ($template->subject === null || $template->subject === '')) {
            throw AgentRefusal::invalid("Pass a subject for the email template {$key}.");
        }
        if ($channel === MessageTemplate::EMAIL) {
            $others = MessageTemplate::query()->where('account_id', $accountId)->where('channel', $channel)->when($template->exists, fn ($q) => $q->whereKeyNot($template->id));
            if ($isDefault === false && $template->is_default && $others->exists()) {
                throw AgentRefusal::invalid('Set another template as the default first.', ['field' => 'is_default']);
            }
            if ($isDefault === true || ! $others->exists()) {
                (clone $others)->where('is_default', true)->update(['is_default' => false]);
                $template->is_default = true;
            }
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
            $sms = MessageTemplate::query()->where('account_id', $accountId)->where('channel', MessageTemplate::SMS);
            $template = (clone $sms)->where('key', $attributes['template'])->first()
                ?? throw AgentRefusal::invalid("No text message template {$attributes['template']}.", ['valid_templates' => $sms->pluck('key')->all()]);
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
