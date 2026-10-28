<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\I18NextTranslationsLoader;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(private readonly I18NextTranslationsLoader $translationsLoader) {}

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'auth' => function () use ($request) {
                return [
                    'user' => $request->user() ? [
                        'id' => $request->user()->id,
                        'first_name' => $request->user()->first_name,
                        'last_name' => $request->user()->last_name,
                        'name' => $request->user()->name,
                        'email' => $request->user()->email,
                        'owner' => $request->user()->owner,
                        'account' => [
                            'id' => $request->user()->account->id,
                            'name' => $request->user()->account->name,
                            'is_test' => $request->user()->account->is_test,
                            'features' => $request->user()->account->features(),
                        ],
                    ] : null,
                ];
            },
            'locale' => fn () => $locale,
            'translations' => ! $request->inertia() ? [
                $locale => [
                    'translation' => $this->translationsLoader->loadTranslations($locale),
                ],
            ] : null,
            // Inertia::always() so the closure runs on EVERY response — including
            // partial reloads. Without it, partial reloads (`X-Inertia-Partial-Data: task`)
            // skip shared props, so the React state keeps the post-redirect flash
            // forever, and every poll re-fires the toast. Combined with pull()
            // (consume on first read), the first full response shows the toast
            // and every subsequent partial reload returns null and clears the
            // stale frontend state.
            'flash' => Inertia::always(function () use ($request) {
                return [
                    'success' => $request->session()->pull('success'),
                    'error' => $request->session()->pull('error'),
                    // A month close or reopen, as figures, so the toast words and formats it itself.
                    'settlement' => $request->session()->pull('settlement'),
                    'syncUuid' => $request->session()->get('syncUuid'),
                ];
            }),
        ];
    }
}
