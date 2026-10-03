<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\EncryptedEnvelope;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Laravel's encrypted string cast, except that a value this APP_KEY cannot
 * decrypt reads as null instead of throwing, and stays stored as it is until
 * something writes the attribute. Integration::unreadableSecrets() names it.
 */
final class TolerantEncrypted implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return EncryptedEnvelope::looksEncrypted($value) ? EncryptedEnvelope::tryDecrypt($value) : $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Crypt::encryptString((string) $value);
    }
}
