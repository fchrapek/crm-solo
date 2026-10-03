<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\SettingsWithSecrets;
use App\Casts\TolerantEncrypted;
use App\Exceptions\UnreadableIntegrationCredentials;
use App\Support\EncryptedEnvelope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Integration extends Model
{
    public const PROVIDERS = [
        'infakt' => [
            'name' => 'Infakt',
            'description' => 'Polish invoicing and accounting platform',
            'website' => 'https://www.infakt.pl',
            'features' => ['clients', 'invoices'],
            'auth_type' => 'api_key',
            'secret_fields' => ['api_key'],
            'settings_fields' => [],
        ],
        'trello' => [
            'name' => 'Trello',
            'description' => 'Sync project boards and tasks for client management',
            'website' => 'https://trello.com',
            'features' => ['projects', 'tasks'],
            'auth_type' => 'api_key',
            'secret_fields' => ['api_key', 'trello_api_key'],
            'settings_fields' => [],
        ],
    ];

    /** Keys inside settings that hold credentials, stored encrypted. */
    public const SECRET_SETTINGS = ['trello_api_key'];

    protected $fillable = [
        'account_id',
        'provider',
        'is_enabled',
        'api_key',
        'settings',
        'last_synced_at',
        'last_sync_error',
    ];

    /**
     * Credential fields the edit form takes for a provider: `api_key` is the
     * column, any other name is a key inside settings.
     *
     * @return list<string>
     */
    public static function secretFields(string $provider): array
    {
        return self::PROVIDERS[$provider]['secret_fields'] ?? [];
    }

    /**
     * Non-secret keys the edit form may write into settings; anything else a
     * request sends under `settings` is dropped. Credentials have their own fields.
     *
     * @return list<string>
     */
    public static function settingsFields(string $provider): array
    {
        return self::PROVIDERS[$provider]['settings_fields'] ?? [];
    }

    /**
     * Stored credentials this APP_KEY cannot decrypt (written under another
     * key). They read as missing and stay stored as they are.
     *
     * @return list<string>
     */
    public function unreadableSecrets(): array
    {
        $unreadable = [];

        if (EncryptedEnvelope::isUnreadable($this->getRawOriginal('api_key'))) {
            $unreadable[] = 'api_key';
        }

        $settings = SettingsWithSecrets::decode($this->getRawOriginal('settings')) ?? [];
        foreach (self::SECRET_SETTINGS as $key) {
            if (EncryptedEnvelope::isUnreadable($settings[$key] ?? null)) {
                $unreadable[] = $key;
            }
        }

        return $unreadable;
    }

    /**
     * @throws UnreadableIntegrationCredentials
     */
    public function assertSecretsReadable(): void
    {
        $unreadable = $this->unreadableSecrets();

        if ($unreadable !== []) {
            throw new UnreadableIntegrationCredentials($this->provider, $unreadable);
        }
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function hasValidApiKey(): bool
    {
        return ! empty($this->api_key);
    }

    public function hasValidOAuthTokens(): bool
    {
        $settings = $this->settings ?? [];

        return ! empty($settings['access_token']) && ! empty($settings['refresh_token']);
    }

    public function isOAuthProvider(): bool
    {
        $config = $this->getProviderConfig();

        return ($config['auth_type'] ?? 'api_key') === 'oauth2';
    }

    public function isConfigured(): bool
    {
        if ($this->isOAuthProvider()) {
            return $this->hasValidOAuthTokens();
        }

        if ($this->provider === 'trello') {
            $settings = $this->settings ?? [];

            return $this->hasValidApiKey() && ! empty($settings['trello_api_key']);
        }

        return $this->hasValidApiKey();
    }

    public function getProviderConfig(): ?array
    {
        return self::PROVIDERS[$this->provider] ?? null;
    }

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'api_key' => TolerantEncrypted::class,
            'settings' => SettingsWithSecrets::class.':'.implode(',', self::SECRET_SETTINGS),
            'last_synced_at' => 'datetime',
        ];
    }
}
