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
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Closed mode sends guests to the /czesc splash; /login still works.
        $middleware->redirectGuestsTo(fn () => config('waitlist.closed') ? route('czesc') : route('login'));
        $middleware->redirectUsersTo(AppServiceProvider::HOME);
        $middleware->encryptCookies(except: ['appearance']);

        // SecurityHeaders is outermost so early refusals (unknown host, 413,
        // maintenance) carry the headers too.
        $middleware->prepend([
            App\Http\Middleware\SecurityHeaders::class,
            App\Http\Middleware\RejectUnknownHosts::class,
        ]);
        // Verb arguments reach the command exactly as the shim sent them, as they would locally.
        $middleware->trimStrings(except: [fn (Request $request): bool => $request->is('agent/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request): bool => $request->is('agent/*')]);

        $middleware->alias([
            'agent.token' => App\Http\Middleware\AuthenticateAgentToken::class,
            'host-exec' => App\Http\Middleware\EnsureHostExecEnabled::class,
            'closed' => App\Http\Middleware\EnsureClosedMode::class,
            'not-in-demo' => App\Http\Middleware\AbsentInDemo::class,
        ]);

        $middleware->web(append: [
            HandleAppearanceMiddleware::class,
            App\Http\Middleware\HandleInertiaRequests::class,
            Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ], prepend: [
            App\Http\Middleware\EnsureRequestIsSecure::class,
            SetLocaleMiddleware::class,
        ]);

        // Trusted proxies come from config/trustedproxy.php (TRUSTED_PROXIES).
        $middleware->replace(Illuminate\Http\Middleware\TrustProxies::class, App\Http\Middleware\TrustProxies::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            // Agent transports are machine clients: never an HTML error page or a redirect.
            if ($request->is('mcp', 'agent/*')) {
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
