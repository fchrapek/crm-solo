<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/** Cloudflare Turnstile siteverify, failing closed; off without TURNSTILE_SECRET unless a form passes its own secret, hostnames and action. */
final class Turnstile implements ValidationRule
{
    /** @var bool Run even when the attribute is absent from the request. */
    public $implicit = true;

    /** @param  list<string>|null  $hostnames */
    public function __construct(
        #[SensitiveParameter] private readonly ?string $secret = null,
        private readonly ?array $hostnames = null,
        private readonly ?string $action = null,
        private readonly ?string $idempotencyKey = null,
    ) {}

    /** The widget's site key, only when the backend will verify the token. */
    public static function siteKey(): ?string
    {
        return config('services.turnstile.secret')
            ? (string) config('services.turnstile.site_key')
            : null;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $secret = $this->secret ?? (string) config('services.turnstile.secret');
        if ($secret === '' && $this->secret === null) {
            return;
        }
        if ($secret === '') {
            $fail(__('turnstile_failed'));

            return;
        }

        if (! is_string($value) || $value === '') {
            $fail(__('turnstile_failed'));

            return;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array_filter([
                    'secret' => $secret,
                    'response' => $value,
                    'remoteip' => request()->ip(),
                    'idempotency_key' => $this->idempotencyKey,
                ], fn (?string $field): bool => $field !== null));
        } catch (Throwable) {
            $fail(__('turnstile_failed'));

            return;
        }

        if (! $response->ok() || $response->json('success') !== true) {
            $fail(__('turnstile_failed'));

            return;
        }

        if ($this->hostnames !== null && ! in_array($response->json('hostname'), $this->hostnames, true)) {
            $fail(__('turnstile_failed'));

            return;
        }

        if ($this->action !== null && $response->json('action') !== $this->action) {
            $fail(__('turnstile_failed'));
        }
    }
}
