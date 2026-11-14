<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Day\DayPlanner;
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

    public function __construct(
        private readonly I18NextTranslationsLoader $translationsLoader,
        private readonly DayPlanner $planner,
    ) {}

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
                            'features' => $request->user()->account->features($request->user()),
                        ],
                    ] : null,
                ];
            },
            'timer' => fn () => $this->runningTimer($request),
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

    /**
     * The newest running timer, for the shell's rail chip and phone strip.
     *
     * @return array{id: int, started_at: string|null, task: string|null, client: string|null}|null
     */
    private function runningTimer(Request $request): ?array
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }

        $entry = $this->planner->running($user->account_id);

        return $entry === null ? null : [
            'id' => $entry->id,
            'started_at' => $entry->start_time?->toIso8601String(),
            'task' => $entry->task?->name ?? $entry->description,
            'client' => $entry->client?->name,
        ];
    }
}
