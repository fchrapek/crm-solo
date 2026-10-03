<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\EncryptedEnvelope;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * A JSON settings array whose named keys are stored encrypted with APP_KEY
 * and read back in plain text.
 *
 * A stored value that is not an encrypted envelope is legacy plain text and
 * reads as it is. An envelope this APP_KEY cannot open is left out of the
 * array on read and kept byte for byte on write, unless the caller sets that
 * key to a new value or to null (removal); Integration::unreadableSecrets()
 * names it.
 */
final class SettingsWithSecrets implements CastsAttributes
{
    /** @var list<string> */
    private readonly array $secretKeys;

    public function __construct(string ...$secretKeys)
    {
        $this->secretKeys = array_values($secretKeys);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decode(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        $settings = self::decode($value);
        if ($settings === null) {
            return null;
        }

        foreach ($this->secretKeys as $secret) {
            $stored = $settings[$secret] ?? null;
            if (! is_string($stored) || $stored === '' || ! EncryptedEnvelope::looksEncrypted($stored)) {
                continue;
            }

            $plain = EncryptedEnvelope::tryDecrypt($stored);
            if ($plain === null) {
                unset($settings[$secret]);
            } else {
                $settings[$secret] = $plain;
            }
        }

        return $settings;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $settings = (array) $value;
        $stored = self::decode($attributes[$key] ?? null) ?? [];

        foreach ($this->secretKeys as $secret) {
            $previous = $stored[$secret] ?? null;

            if (! array_key_exists($secret, $settings)) {
                // Hidden on read because it does not decrypt: keep the ciphertext as it is.
                if (EncryptedEnvelope::isUnreadable($previous)) {
                    $settings[$secret] = $previous;
                }

                continue;
            }

            $plain = $settings[$secret];
            if ($plain === null || $plain === '') {
                unset($settings[$secret]);

                continue;
            }

            // An unchanged secret keeps its ciphertext, so saving other fields does not rewrite it.
            $settings[$secret] = is_string($previous) && EncryptedEnvelope::looksEncrypted($previous) && EncryptedEnvelope::tryDecrypt($previous) === $plain
                ? $previous
                : Crypt::encryptString((string) $plain);
        }

        return json_encode($settings, JSON_THROW_ON_ERROR);
    }
}
