<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\Turnstile;
use Illuminate\Foundation\Http\FormRequest;

final class WaitlistRequest extends FormRequest
{
    /** Hidden from people; a bot that fills it gets the thank-you and no lead. */
    public const HONEYPOT = 'website';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            // Implicit rule: no-op unless TURNSTILE_SECRET is configured.
            'cf-turnstile-response' => [new Turnstile],
        ];
    }

    public function isBot(): bool
    {
        $value = $this->input(self::HONEYPOT);

        // People never see the field, so anything but an empty string is a bot.
        return $value !== null && (! is_string($value) || mb_trim($value) !== '');
    }
}
