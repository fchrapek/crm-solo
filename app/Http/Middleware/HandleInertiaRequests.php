<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\TimeEntry;
use App\Services\Day\DayPlanner;
use App\Services\I18NextTranslationsLoader;
use App\Support\PrefetchRequest;
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
                        'helper' => ['desktop' => $request->user()->prefers('helper_desktop'), 'phone' => $request->user()->prefers('helper_phone')],
                        'account' => [
                            'id' => $request->user()->account->id,
                            'name' => $request->user()->account->name,
                            'features' => $request->user()->account->features($request->user()),
                        ],
                    ] : null,
                ];
            },
            'timer' => fn () => $this->runningTimers($request)['shown'],
            'timers' => fn () => $this->runningTimers($request)['all'],
            'locale' => fn () => $locale,
            'docsUrl' => fn () => (string) config('services.docs.url'),
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
                // A hover's page may never be shown: the messages stay for the page the user goes to.
                if (PrefetchRequest::is($request)) {
                    $request->session()->reflash();

                    return ['success' => null, 'error' => null, 'settlement' => null, 'syncUuid' => $request->session()->get('syncUuid')];
                }

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
    /**
     * Every running timer, newest first, and the one the shell shows: the newest, or the one `?timer=` names.
     *
     * @return array{shown: array<string, mixed>|null, all: list<array<string, mixed>>}
     */
    private function runningTimers(Request $request): array
    {
        if ($request->attributes->has('shell.timers')) {
            return $request->attributes->get('shell.timers');
        }

        $user = $request->user();
        $all = $user === null ? [] : $this->planner->runningAll($user->account_id)->map(fn (TimeEntry $entry): array => [
            'id' => $entry->id,
            'started_at' => $entry->start_time?->toIso8601String(),
            'task' => $entry->task?->name ?? $entry->description,
            'client' => $entry->client?->name,
            'project_id' => $entry->project_id,
        ])->values()->all();
        $shown = collect($all)->firstWhere('id', (int) $request->query('timer')) ?? ($all[0] ?? null);

        $request->attributes->set('shell.timers', $timers = ['shown' => $shown, 'all' => $all]);

        return $timers;
    }
}
