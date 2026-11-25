<?php

declare(strict_types=1);

use App\Http\Middleware\HandleAppearanceMiddleware;
use App\Http\Middleware\SetLocaleMiddleware;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function (): void {
            Illuminate\Support\Facades\Route::group([], base_path('routes/intake.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Closed mode sends guests to the /waitlist splash; /login still works.
        $middleware->redirectGuestsTo(fn () => config('waitlist.closed') ? route('waitlist') : route('login'));
        // Cloudflare answers `/` without an Access cookie with the splash, so the splash may not answer with `/`.
        $middleware->redirectUsersTo(fn (Request $request): string => $request->routeIs('waitlist', 'waitlist.store') ? route('login') : AppServiceProvider::HOME);
        $middleware->encryptCookies(except: ['appearance']);
        // Sessions and remember cookies stamped with an older password hash are refused.
        $middleware->authenticateSessions();

        // SecurityHeaders is outermost so early refusals (unknown host, 413,
        // maintenance) carry the headers too.
        $middleware->prepend([
            App\Http\Middleware\SecurityHeaders::class,
            App\Http\Middleware\RejectUnknownHosts::class,
        ]);
        // Laravel's global stack with the intake pair right after TrustProxies, so both see the visitor's address, and before maintenance.
        $middleware->use([
            Illuminate\Http\Middleware\ValidatePathEncoding::class,
            Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks::class,
            Illuminate\Http\Middleware\TrustProxies::class,
            App\Http\Middleware\ThrottleRefusedIntake::class,
            App\Http\Middleware\GuardIntakeRequests::class,
            // Global, not on a route: a retired path matches no route, so route middleware would never see it.
            App\Http\Middleware\RedirectLegacyUrls::class,
            Illuminate\Http\Middleware\HandleCors::class,
            Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class,
            Illuminate\Http\Middleware\ValidatePostSize::class,
            Illuminate\Foundation\Http\Middleware\TrimStrings::class,
            Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        ]);
        // Verb arguments reach the command exactly as the shim sent them, as they would locally.
        $middleware->trimStrings(except: [fn (Request $request): bool => $request->is('agent/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request): bool => $request->is('agent/*')]);

        $middleware->alias([
            'agent.token' => App\Http\Middleware\AuthenticateAgentToken::class,
            'closed' => App\Http\Middleware\EnsureClosedMode::class,
            'not-in-demo' => App\Http\Middleware\AbsentInDemo::class,
            'read-only-in-demo' => App\Http\Middleware\ReadOnlyInDemo::class,
            'feature' => App\Http\Middleware\AccountFeature::class,
        ]);

        $middleware->web(append: [
            HandleAppearanceMiddleware::class,
            App\Http\Middleware\HandleInertiaRequests::class,
            Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ], prepend: [
            App\Http\Middleware\ServerTiming::class,
            App\Http\Middleware\EnsureRequestIsSecure::class,
            SetLocaleMiddleware::class,
        ]);

        // Trusted proxies come from config/trustedproxy.php (TRUSTED_PROXIES).
        $middleware->replace(Illuminate\Http\Middleware\TrustProxies::class, App\Http\Middleware\TrustProxies::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Secret fields never go back into the session's old input when a form is refused.
        $exceptions->dontFlash(['turnstile_secret', 'api_key', 'trello_api_key', 'secret', 'token']);
        // A website form reads JSON whatever its Accept header says.
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('intake/*') || $request->expectsJson());
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            // Agent transports, website forms and the token issue fetch read JSON: never an HTML error page or a redirect.
            if ($request->is('mcp', 'agent/*', 'intake/*') || ($request->is('settings/agent-tokens') && $request->expectsJson())) {
                return $response;
            }
            if (! app()->environment(['local', 'testing']) && in_array($response->getStatusCode(), [500, 503, 404, 403])) {
                return Inertia::render('error', ['status' => $response->getStatusCode()])
                    ->toResponse($request)
                    ->setStatusCode($response->getStatusCode());
            }
            if ($response->getStatusCode() === 419) {
                return back()->with([
                    'message' => __('The page expired, please try again.'),
                ]);
            }
            if ($response->getStatusCode() === 429 && ! $request->expectsJson()) {
                return back()->with([
                    'error' => __('Sorry, you are making too many requests to our servers.'),
                ]);
            }

            return $response;
        });
    })->create();
