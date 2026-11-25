<?php

declare(strict_types=1);

namespace App\Services\Messaging\Sender;

use App\Exceptions\SenderUnavailable;
use App\Models\Integration;
use App\Services\Agent\AgentRefusal;
use InvalidArgumentException;
use SensitiveParameter;

/** One SMTP sender per account: an Integration row with provider smtp, the password in the encrypted api_key. */
final class AccountSenders
{
    /** 465 is implicit TLS, 587 and 2525 must offer STARTTLS. */
    public const array PORTS = [465, 587, 2525];

    public function __construct(private readonly SmtpHostGuard $guard) {}

    public function find(int $accountId): ?Integration
    {
        return Integration::query()->where('account_id', $accountId)->where('provider', Integration::SMTP)->first();
    }

    /**
     * What the sender is without its password, which shows only as set or not, and how its last test send went.
     *
     * @return array<string, mixed>
     *
     * @throws AgentRefusal
     */
    public function describe(int $accountId): array
    {
        if (config('app.demo')) {
            throw new AgentRefusal(AgentRefusal::REFUSED, __('The demo has no senders.'));
        }
        $row = $this->find($accountId);
        if ($row === null) {
            return ['configured' => false];
        }
        $settings = $row->settings ?? [];

        return [
            'configured' => true,
            'enabled' => (bool) $row->is_enabled,
            'host' => $settings['host'] ?? null,
            'port' => $settings['port'] ?? null,
            'username' => $settings['username'] ?? null,
            'from_address' => $settings['from_address'] ?? null,
            'from_name' => $settings['from_name'] ?? null,
            'password_set' => (string) $row->getRawOriginal('api_key') !== '',
            'password_readable' => $row->unreadableSecrets() === [],
            'last_test' => $row->last_synced_at === null ? null : [
                'at' => $row->last_synced_at->toIso8601String(),
                'ok' => $row->last_sync_error === null,
                'reason' => $row->last_sync_error,
            ],
        ];
    }

    /** Remembers how a test send went: a reason code, never the server's words. */
    public function recordTest(int $accountId, ?string $reason): void
    {
        $this->find($accountId)?->forceFill(['last_synced_at' => now(), 'last_sync_error' => $reason])->save();
    }

    /**
     * @throws SenderUnavailable
     */
    public function config(int $accountId): SenderConfig
    {
        $row = $this->find($accountId);
        if ($row === null || ! $row->is_enabled) {
            throw SenderUnavailable::none($accountId);
        }
        if ($row->unreadableSecrets() !== []) {
            throw SenderUnavailable::unreadable($accountId);
        }

        $settings = $row->settings ?? [];
        $password = (string) $row->api_key;
        if ($password === '' || ! is_string($settings['host'] ?? null) || ! is_string($settings['from_address'] ?? null)) {
            throw SenderUnavailable::none($accountId);
        }

        return new SenderConfig(
            host: $settings['host'],
            port: (int) ($settings['port'] ?? 0),
            username: (string) ($settings['username'] ?? ''),
            password: $password,
            fromAddress: $settings['from_address'],
            fromName: is_string($settings['from_name'] ?? null) ? $settings['from_name'] : null,
        );
    }

    /**
     * Stores and enables the sender after checking every field and the host; a null password keeps the stored one.
     *
     * @param  array{host: string, port: int, username: string, from_address: string, from_name: ?string}  $fields
     *
     * @throws InvalidArgumentException|SenderUnavailable
     */
    public function save(int $accountId, array $fields, #[SensitiveParameter] ?string $password): Integration
    {
        $fields['host'] = mb_strtolower(mb_trim($fields['host']));
        $fields['from_name'] = is_string($fields['from_name']) && mb_trim($fields['from_name']) !== '' ? mb_trim($fields['from_name']) : null;

        $problems = $this->problems($fields);
        if ($password !== null && ! $this->validPassword($password)) {
            $problems[] = __('The password must be 1 to 1024 characters on one line.');
        }
        if ($problems !== []) {
            throw new InvalidArgumentException(implode(' ', $problems));
        }

        $this->guard->check($fields['host']);

        $row = $this->find($accountId) ?? new Integration(['account_id' => $accountId, 'provider' => Integration::SMTP]);
        if ($password !== null) {
            $row->api_key = $password;
        }
        if ((string) $row->api_key === '') {
            throw new InvalidArgumentException(__('No password is stored yet: give one.'));
        }

        // A test of the old settings says nothing about the new ones.
        $row->fill([
            'is_enabled' => true,
            'settings' => $fields,
            'last_synced_at' => null,
            'last_sync_error' => null,
        ])->save();

        return $row;
    }

    /**
     * @param  array{host: string, port: int, username: string, from_address: string, from_name: ?string}  $fields
     * @return list<string>
     */
    public function problems(array $fields): array
    {
        $local = SmtpHostGuard::allowsLocalRelay();
        $problems = [];

        $host = $fields['host'];
        $isHostname = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false && mb_strlen($host) <= 253;
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if ($local ? ! ($isHostname || $isIp) : (! $isHostname || $isIp || ! str_contains($host, '.'))) {
            $problems[] = __('The host must be a server name the certificate is issued for, not an address.');
        }

        if (! in_array($fields['port'], self::PORTS, true) && ! ($local && $fields['port'] >= 1 && $fields['port'] <= 65535)) {
            $problems[] = __('The port must be 465, 587 or 2525.');
        }

        if ($fields['username'] === '' || mb_strlen($fields['username']) > 255 || $this->hasControl($fields['username'])) {
            $problems[] = __('The username must be 1 to 255 characters on one line.');
        }

        if (filter_var($fields['from_address'], FILTER_VALIDATE_EMAIL) === false || mb_strlen($fields['from_address']) > 255) {
            $problems[] = __('The from address is not an email address.');
        }

        if ($fields['from_name'] !== null && (mb_strlen($fields['from_name']) > 100 || $this->hasControl($fields['from_name']))) {
            $problems[] = __('The from name must be at most 100 characters on one line.');
        }

        return $problems;
    }

    private function validPassword(#[SensitiveParameter] string $password): bool
    {
        return $password !== '' && mb_strlen($password) <= 1024 && ! $this->hasControl($password);
    }

    private function hasControl(string $value): bool
    {
        return preg_match('/\p{Cc}/u', $value) === 1 || ! mb_check_encoding($value, 'UTF-8');
    }
}
