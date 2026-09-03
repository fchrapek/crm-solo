<?php

declare(strict_types=1);

namespace App\Models;

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
        ],
        'trello' => [
            'name' => 'Trello',
            'description' => 'Sync project boards and tasks for client management',
            'website' => 'https://trello.com',
            'features' => ['projects', 'tasks'],
            'auth_type' => 'api_key',
        ],
        'clockify' => [
            'name' => 'Clockify',
            'description' => 'Time tracking and work hours reporting',
            'website' => 'https://clockify.me',
            'features' => ['time_entries'],
            'auth_type' => 'api_key',
        ],
    ];

    protected $fillable = [
        'account_id',
        'provider',
        'is_enabled',
        'api_key',
        'settings',
        'last_synced_at',
        'last_sync_error',
    ];

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
            'api_key' => 'encrypted',
            'settings' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
