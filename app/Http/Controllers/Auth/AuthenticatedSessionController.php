<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Providers\AppServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view. On the public demo instance the credentials are
     * shown and prefilled — the data is fictional and resets nightly, so the
     * login form is a doorway, not a gate.
     */
    public function create(): Response
    {
        return Inertia::render('auth/login', [
            'demo' => config('app.demo') ? [
                'email' => 'demo@crm-solo.test',
                'password' => (string) config('app.demo_password'),
            ] : null,
            // Widget renders only when the backend will actually verify -
            // a visible challenge without enforcement would be pure friction.
            'turnstileSiteKey' => config('services.turnstile.secret')
                ? (string) config('services.turnstile.site_key')
                : null,
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(AppServiceProvider::HOME);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
