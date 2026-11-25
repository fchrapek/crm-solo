<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IntakeForm;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/** Runs right after TrustProxies, so it counts the visitor's address, and budgets the refused intake requests the route limits never see. */
final class ThrottleRefusedIntake
{
    public const string WRONG_SECRET = 'intake_wrong_secret';

    private const array REFUSALS = [401, 403, 404, 413, 415];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('intake/*')) {
            return $next($request);
        }

        $client = GuardIntakeRequests::clientKey($request->ip());
        $windows = ["intake-ip-m|{$client}" => [(int) config('intake.ip_per_minute'), 60], "intake-ip-h|{$client}" => [(int) config('intake.ip_per_hour'), 3600]];

        foreach ($windows as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return $this->limited($request, RateLimiter::availableIn($key));
            }
        }

        $response = $next($request);
        $guess = $response->getStatusCode() === 429 && $request->attributes->get(self::WRONG_SECRET) === true;
        if ($guess || in_array($response->getStatusCode(), self::REFUSALS, true)) {
            foreach ($windows as $key => [, $decay]) {
                RateLimiter::hit($key, $decay);
            }
        }

        return $response;
    }

    private function limited(Request $request, int $retryAfter): Response
    {
        $response = GuardIntakeRequests::status('too_many', 429);
        $response->headers->set('Retry-After', (string) $retryAfter);
        $response->headers->set('Vary', 'Origin', false);

        $origin = $request->headers->get('Origin');
        try {
            $form = IntakeForm::active((string) $request->segment(2));
        } catch (QueryException) {
            $form = null;
        }
        if ($form?->allowsOrigin($origin) === true) {
            $response->headers->set('Access-Control-Allow-Origin', (string) $origin);
            $response->headers->set('Access-Control-Expose-Headers', 'Retry-After');
        }

        return $response;
    }
}
