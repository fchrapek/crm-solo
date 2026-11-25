<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\TolerantEncrypted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A website form posting leads to /intake/{public_key}; account, pipeline and source come only from this row. */
final class IntakeForm extends Model
{
    public const int KEY_LENGTH = 32;

    protected $fillable = [
        'account_id',
        'name',
        'public_key',
        'allowed_origins',
        'pipeline',
        'source',
        'notify_to',
        'turnstile_secret',
        'webhook_secret_hash',
        'is_active',
    ];

    protected $hidden = ['turnstile_secret', 'webhook_secret_hash'];

    public static function newPublicKey(): string
    {
        return bin2hex(random_bytes(self::KEY_LENGTH / 2));
    }

    /** Only a lowercase hex key reaches the lookup, since MariaDB compares the column without case. */
    public static function active(string $key): ?self
    {
        if (preg_match('/\A[0-9a-f]{'.self::KEY_LENGTH.'}\z/', $key) !== 1) {
            return null;
        }

        return self::query()->where('public_key', $key)->where('is_active', true)->first();
    }

    public function allowsOrigin(?string $origin): bool
    {
        return is_string($origin) && in_array($origin, $this->allowed_origins ?? [], true);
    }

    /** @return list<string> */
    public function allowedHosts(): array
    {
        return array_values(array_filter(array_map(fn (string $origin): ?string => parse_url($origin, PHP_URL_HOST) ?: null, $this->allowed_origins ?? [])));
    }

    public function webhookSecretMatches(string $presented): bool
    {
        return is_string($this->webhook_secret_hash) && hash_equals($this->webhook_secret_hash, hash('sha256', $presented));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function intakes(): HasMany
    {
        return $this->hasMany(LeadIntake::class);
    }

    protected function casts(): array
    {
        return [
            'allowed_origins' => 'array',
            'is_active' => 'boolean',
            'turnstile_secret' => TolerantEncrypted::class,
        ];
    }
}
