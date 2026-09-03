<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cloudflare Turnstile verification via the canonical siteverify call.
 *
 * Gate, don't replace: the protected handler's logic stays untouched; this
 * rule only decides whether the request may reach it. Fails CLOSED on any
 * siteverify irregularity (network error, non-2xx, non-JSON, success!==true).
 * When TURNSTILE_SECRET is not configured (local dev, CI) the gate is off and
 * the rule passes silently - the widget is not rendered in that case either.
 *
 * Implicit rule: it must run even when the request carries no
 * cf-turnstile-response field at all, otherwise a bot could skip the check
 * by omitting the token.
 */
final class Turnstile implements ValidationRule
{
    /** @var bool Run even when the attribute is absent from the request. */
    public $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $secret = (string) config('services.turnstile.secret');
        if ($secret === '') {
            return;
        }

        if (! is_string($value) || $value === '') {
            $fail(__('turnstile_failed'));

            return;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => $secret,
                    'response' => $value,
                    'remoteip' => request()->ip(),
                ]);
        } catch (Throwable) {
            $fail(__('turnstile_failed'));

            return;
        }

        if (! $response->ok() || $response->json('success') !== true) {
            $fail(__('turnstile_failed'));
        }
    }
}
