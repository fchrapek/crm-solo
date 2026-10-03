<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Recognises Laravel's encrypted payload (base64 of a JSON object with iv,
 * value and mac) by its shape, so ciphertext written under another APP_KEY is
 * never mistaken for a plain-text credential.
 */
final class EncryptedEnvelope
{
    public static function looksEncrypted(string $value): bool
    {
        $json = base64_decode($value, true);
        if ($json === false) {
            return false;
        }

        $payload = json_decode($json, true);

        return is_array($payload)
            && is_string($payload['iv'] ?? null)
            && is_string($payload['value'] ?? null)
            && is_string($payload['mac'] ?? null);
    }

    /**
     * The plain text, or null when the envelope does not open with this APP_KEY.
     */
    public static function tryDecrypt(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * True for an envelope this APP_KEY cannot open.
     */
    public static function isUnreadable(mixed $value): bool
    {
        return is_string($value) && self::looksEncrypted($value) && self::tryDecrypt($value) === null;
    }
}
