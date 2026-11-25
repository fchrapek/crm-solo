<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IntakeForm;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Global guard for intake/*: form lookup, preflight, Origin, size and content type, and CORS on every answer. */
final class GuardIntakeRequests
{
    public const string FORM = 'intake_form';

    public static function status(string $status, int $code): JsonResponse
    {
        return new JsonResponse(['status' => $status], $code);
    }

    /** An IPv6 visitor is counted by its /64, since one host usually holds the whole prefix. */
    public static function clientKey(?string $ip): string
    {
        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return bin2hex(mb_substr((string) inet_pton($ip), 0, 8, '8bit')).'::/64';
        }

        return (string) $ip;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('intake/*')) {
            return $next($request);
        }
        if (config('app.demo')) {
            return self::status('not_found', 404);
        }

        try {
            $form = IntakeForm::active((string) $request->segment(2));
        } catch (QueryException $e) {
            Log::error('Intake form lookup failed.', ['exception' => $e::class, 'sqlstate' => $e->errorInfo[0] ?? null]);

            return $this->cors(self::status('error', 500), null);
        }
        if ($form === null) {
            return self::status('not_found', 404);
        }

        $origin = $request->headers->get('Origin');
        $webhook = $request->headers->has('Authorization');

        if ($request->isMethod('OPTIONS')) {
            if (! $form->allowsOrigin($origin)) {
                return self::status('forbidden', 403);
            }

            return $this->cors(new Response('', 204, [
                'Access-Control-Allow-Methods' => 'POST',
                'Access-Control-Allow-Headers' => 'Content-Type',
                'Access-Control-Max-Age' => '600',
            ]), $origin);
        }

        if (! $webhook && ! $form->allowsOrigin($origin)) {
            return self::status('forbidden', 403);
        }
        $allowed = $webhook ? null : $origin;

        if ($request->isMethod('POST')) {
            // Caddy refuses a larger body before PHP; this check covers a stack without it.
            $max = (int) config('intake.max_bytes');
            $declared = $request->headers->get('Content-Length');
            if ((is_numeric($declared) && (int) $declared > $max) || mb_strlen($request->getContent(), '8bit') > $max) {
                return $this->cors(self::status('too_large', 413), $allowed);
            }

            $type = mb_strtolower(mb_trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
            if ($type !== 'application/json') {
                return $this->cors(self::status('unsupported_type', 415), $allowed);
            }
        }

        $request->attributes->set(self::FORM, $form);

        return $this->cors($next($request), $allowed);
    }

    private function cors(Response $response, ?string $origin): Response
    {
        if ($origin !== null) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Expose-Headers', 'Retry-After');
        }
        $response->headers->set('Vary', 'Origin', false);

        return $response;
    }
}
