<?php

declare(strict_types=1);

use App\Support\EncryptedEnvelope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Encrypt the credential keys inside integrations.settings (the Trello app
 * key) with APP_KEY. A value that is already an encrypted envelope is left
 * alone, whichever key made it, so the migration can run twice; down()
 * decrypts back to plain text what this APP_KEY can open. Values are never
 * printed.
 */
return new class extends Migration
{
    private const SECRET_SETTINGS = ['trello_api_key'];

    public function up(): void
    {
        $this->rewrite(fn (string $value): string => EncryptedEnvelope::looksEncrypted($value) ? $value : Crypt::encryptString($value));
    }

    public function down(): void
    {
        // An envelope this APP_KEY cannot open is left as it is, never turned into "plain text".
        $this->rewrite(fn (string $value): string => EncryptedEnvelope::looksEncrypted($value) ? (EncryptedEnvelope::tryDecrypt($value) ?? $value) : $value);
    }

    /**
     * @param  callable(string): string  $transform
     */
    private function rewrite(callable $transform): void
    {
        DB::table('integrations')->whereNotNull('settings')->orderBy('id')->each(function (object $row) use ($transform): void {
            $settings = json_decode((string) $row->settings, true);
            if (! is_array($settings)) {
                return;
            }

            $changed = false;
            foreach (self::SECRET_SETTINGS as $key) {
                if (! is_string($settings[$key] ?? null) || $settings[$key] === '') {
                    continue;
                }

                $next = $transform($settings[$key]);
                if ($next !== $settings[$key]) {
                    $settings[$key] = $next;
                    $changed = true;
                }
            }

            if ($changed) {
                DB::table('integrations')->where('id', $row->id)->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR)]);
            }
        });
    }
};
