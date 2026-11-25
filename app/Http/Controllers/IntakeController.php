<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\GuardIntakeRequests;
use App\Models\IntakeForm;
use App\Rules\Turnstile;
use App\Services\Leads\FormIntake;
use App\Services\Leads\IntakeExtras;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;
use Symfony\Component\Mime\Address;
use Throwable;

/** A website form's post: caller, body, fields, honeypot, replay, daily quota, Turnstile, then the write. */
final class IntakeController extends Controller
{
    public const string HONEYPOT = 'website';

    public function __invoke(Request $request, FormIntake $intake): JsonResponse
    {
        /** @var IntakeForm $form */
        $form = $request->attributes->get(GuardIntakeRequests::FORM);

        // A request with Authorization is a webhook and never falls back to the browser checks.
        $webhook = $request->headers->has('Authorization');
        if ($webhook && ! $form->webhookSecretMatches((string) self::bearer($request))) {
            return GuardIntakeRequests::status('unauthorized', 401);
        }
        if ($webhook && ($limited = $this->overWebhookBudget($form)) !== null) {
            return $limited;
        }

        $secret = $webhook ? '' : (string) $form->turnstile_secret;
        if (! $webhook && $secret === '' && ! app()->environment(['local', 'testing'])) {
            return GuardIntakeRequests::status('unavailable', 503);
        }

        $body = json_decode($request->getContent(), false, 8);
        if (! $body instanceof stdClass) {
            return $this->invalid(['body']);
        }
        $payload = (array) json_decode($request->getContent(), true, 8);

        $validator = Validator::make($payload, [
            'name' => ['required', 'string', 'max:120', 'not_regex:/\p{Cc}/u'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'message' => ['required', 'string', 'max:5000', 'not_regex:/[^\P{Cc}\r\n\t]/u'],
            'company' => ['nullable', 'string', 'max:255', 'not_regex:/\p{Cc}/u'],
            'idempotency_key' => [$webhook ? 'nullable' : 'required', 'string', 'uuid'],
            'lang' => ['nullable', 'string', 'in:en,pl'],
            'page_url' => ['nullable', 'string', 'max:2048', 'url:https,http'],
            'cf-turnstile-response' => ['nullable', 'string', 'max:2048'],
        ]);
        $failed = array_keys($validator->errors()->toArray());
        $pageUrl = $payload['page_url'] ?? null;
        if (is_string($pageUrl) && ! in_array('page_url', $failed, true) && ! $form->allowsOrigin($this->originOf($pageUrl))) {
            $failed[] = 'page_url';
        }
        if (is_string($payload['email'] ?? null) && ! in_array('email', $failed, true) && ! self::isMailbox($payload['email'])) {
            $failed[] = 'email';
        }
        $extras = IntakeExtras::from($body, $webhook);
        if ($extras === null) {
            $failed[] = 'extras';
        }
        if ($failed !== []) {
            return $this->invalid($failed);
        }

        // A server's own "website" field is data, so only a browser post has a honeypot.
        $honeypot = $webhook ? null : ($payload[self::HONEYPOT] ?? null);
        if ($honeypot !== null && (! is_string($honeypot) || mb_trim($honeypot) !== '')) {
            Log::info('Intake honeypot hit.', ['form' => $form->id]);

            return GuardIntakeRequests::status('received', 201);
        }

        $fields = [
            'name' => mb_trim($payload['name']),
            'email' => mb_strtolower(mb_trim($payload['email'])),
            'message' => mb_trim($payload['message']),
            'company' => self::blankToNull($payload['company'] ?? null),
            'lang' => self::blankToNull($payload['lang'] ?? null),
            'page_url' => self::blankToNull($pageUrl),
            'extras' => $extras,
        ];
        // A webhook without a key gets no replay protection.
        $key = mb_strtolower($payload['idempotency_key'] ?? (string) Str::uuid());

        try {
            $replay = $intake->replay($form, $key, FormIntake::fingerprint($fields));
            if ($replay !== null) {
                return $this->answer($replay);
            }

            // Only new leads use up the day, so a replay still gets its 200 or 409.
            $quota = "intake-d|{$form->id}";
            if (RateLimiter::tooManyAttempts($quota, (int) config('intake.new_leads_per_day'))) {
                $response = GuardIntakeRequests::status('too_many', 429);
                $response->headers->set('Retry-After', (string) RateLimiter::availableIn($quota));

                return $response;
            }

            if (! $webhook && $secret !== '') {
                $rule = new Turnstile($secret, [(string) parse_url((string) $request->headers->get('Origin'), PHP_URL_HOST)], (string) config('intake.turnstile_action'), $key);
                if (Validator::make($payload, ['cf-turnstile-response' => [$rule]])->fails()) {
                    return $this->invalid(['cf-turnstile-response']);
                }
            }

            $result = $intake->record($form, $fields, $key);
            if ($result === FormIntake::CREATED) {
                RateLimiter::hit($quota, 86400);
            }

            return $this->answer($result);
        } catch (InvalidArgumentException $e) {
            Log::error('Intake form is misconfigured.', ['form' => $form->id, 'exception' => $e::class]);

            return GuardIntakeRequests::status('unavailable', 503);
        } catch (QueryException $e) {
            Log::error('Intake write failed.', ['form' => $form->id, 'exception' => $e::class, 'sqlstate' => $e->errorInfo[0] ?? null]);

            return GuardIntakeRequests::status('error', 500);
        }
    }

    /** Exactly one Authorization value, "Bearer" in any case and one token, else null. */
    public static function bearer(Request $request): ?string
    {
        $values = $request->headers->all('authorization');
        if (count($values) !== 1 || preg_match('/\A\s*bearer\s+(\S+)\s*\z/i', (string) $values[0], $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /** The address must also be one the mailer accepts, or the lead notice's Reply-To would fail after the commit. */
    private static function isMailbox(string $email): bool
    {
        try {
            new Address(mb_trim($email));
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private static function blankToNull(mixed $value): ?string
    {
        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : null;
    }

    /** Counted only once the secret matched, so junk headers cannot use up a form's webhook budget. */
    private function overWebhookBudget(IntakeForm $form): ?JsonResponse
    {
        $windows = ["intake-wm|{$form->id}" => [(int) config('intake.webhook_per_minute'), 60], "intake-wh|{$form->id}" => [(int) config('intake.webhook_per_hour'), 3600]];
        foreach ($windows as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $response = GuardIntakeRequests::status('too_many', 429);
                $response->headers->set('Retry-After', (string) RateLimiter::availableIn($key));

                return $response;
            }
        }
        foreach ($windows as $key => [, $decay]) {
            RateLimiter::hit($key, $decay);
        }

        return null;
    }

    private function answer(string $result): JsonResponse
    {
        return match ($result) {
            FormIntake::CREATED => GuardIntakeRequests::status('received', 201),
            FormIntake::REPLAY => GuardIntakeRequests::status('received', 200),
            default => GuardIntakeRequests::status('conflict', 409),
        };
    }

    /** @param  list<string>  $fields */
    private function invalid(array $fields): JsonResponse
    {
        return new JsonResponse(['status' => 'invalid', 'errors' => array_values(array_unique($fields))], 422);
    }

    private function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return mb_strtolower($parts['scheme']).'://'.mb_strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
