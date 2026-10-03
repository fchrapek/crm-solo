<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Task;
use App\Services\I18NextTranslationsLoader;
use App\Support\HostExec;
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
                        ],
                    ] : null,
                ];
            },
            'locale' => fn () => $locale,
            // Host the browser uses to reach ttyd iframes — 'localhost' when
            // browser and server share a machine, the server's hostname on
            // LAN/remote self-hosts (TERMINAL_SESSION_HOST).
            'terminal_session_host' => fn (): string => (string) config('terminal.session_host'),
            'host_exec' => fn (): bool => HostExec::enabled(),
            'translations' => ! $request->inertia() ? [
                $locale => [
                    'translation' => $this->translationsLoader->loadTranslations($locale),
                ],
            ] : null,
            // Tasks with a live terminal session — used by the frontend
            // SessionAttentionListener to know which `reverb.session.{id}`
            // channels to subscribe to, and to render the kanban "waiting"
            // dot for sessions whose attention fired while we were elsewhere.
            // Wrapped in Inertia::always so polled partial reloads still
            // pick up new/closed sessions (see the flash comment below).
            'live_sessions' => Inertia::always(function () use ($request) {
                if (! $request->user()) {
                    return [];
                }

                return Task::query()
                    ->whereNotNull('session_pid')
                    ->whereHas('project', fn ($q) => $q->where('account_id', $request->user()->account_id))
                    ->with('project:id,client_id,name')
                    ->get(['id', 'project_id', 'name', 'session_attention_at'])
                    ->map(fn (Task $t) => [
                        'task_id' => $t->id,
                        'task_name' => $t->name,
                        'project_id' => $t->project_id,
                        'client_id' => $t->project?->client_id,
                        'session_attention_at' => $t->session_attention_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all();
            }),
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
                    'syncUuid' => $request->session()->get('syncUuid'),
                ];
            }),
        ];
    }
}
