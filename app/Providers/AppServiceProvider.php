<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Agent\AgentIdentityResolver;
use App\Services\Agent\OwnerIdentityResolver;
use App\Services\AI\AIProviderInterface;
use App\Services\AI\AIServiceManager;
use App\Services\ProjectPreviewLauncher;
use App\Services\ProjectPreviewLauncherInterface;
use App\Services\Reports\Composers\AiNarrativeComposer;
use App\Services\Reports\Composers\StructuredListComposer;
use App\Services\Reports\NarrativePromptResolver;
use App\Services\Reports\ReportComposerRegistry;
use App\Services\Reports\SettingsNarrativePromptResolver;
use App\Services\TaskSources\Providers\TrelloTaskSourceProvider;
use App\Services\TaskSources\TaskSourceRegistry;
use App\Services\TerminalSessionLauncher;
use App\Services\TerminalSessionLauncherInterface;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Throwable;

final class AppServiceProvider extends ServiceProvider
{
    public const string HOME = '/';

    public function register(): void
    {
        JsonResource::withoutWrapping();

        $this->app->singleton(AIServiceManager::class);
        $this->app->bind(AIProviderInterface::class, fn ($app) => $app->make(AIServiceManager::class)->getProvider());

        $this->app->bind(TerminalSessionLauncherInterface::class, TerminalSessionLauncher::class);
        $this->app->bind(ProjectPreviewLauncherInterface::class, ProjectPreviewLauncher::class);

        // Local transports carry no credentials, so agent writes are attributed
        // to the account owner. A hosted transport rebinds this to derive the
        // identity from the request token.
        $this->app->bind(AgentIdentityResolver::class, OwnerIdentityResolver::class);

        // Report wording resolves from the account's settings row, falling
        // back to the prompt shipped in resources/prompts. Same defaults-in-
        // code, customization-in-data split as the leadgen vocabulary below.
        $this->app->bind(NarrativePromptResolver::class, SettingsNarrativePromptResolver::class);

        $this->app->singleton(ReportComposerRegistry::class, function ($app): ReportComposerRegistry {
            $registry = new ReportComposerRegistry;
            // AiNarrativeComposer is the default — most reports want prose.
            // StructuredListComposer remains registered as a no-AI fallback
            // and is the second option in the Generate dialog.
            $registry->register($app->make(AiNarrativeComposer::class), default: true);
            $registry->register($app->make(StructuredListComposer::class));

            return $registry;
        });

        $this->app->singleton(TaskSourceRegistry::class, function ($app): TaskSourceRegistry {
            $registry = new TaskSourceRegistry;
            // Trello is the sole task-source provider today; GitHub Issues is
            // the planned second implementation behind the same interface.
            $registry->register($app->make(TrelloTaskSourceProvider::class));

            return $registry;
        });
    }

    public function boot(): void
    {
        // Behind Cloudflare/Traefik, TLS terminates at the edge and the proxy
        // forwards over http, so Laravel generates http:// URLs. That makes the
        // post-login 302 point at http://<host> while the page origin is https,
        // so the browser blocks the Inertia XHR follow and the SPA appears stuck
        // on /login until a manual reload. Forcing https whenever APP_URL is
        // https fixes generated URLs on dev/prod and leaves local http alone.
        if (Str::startsWith((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Vite::prefetch(concurrency: 3);

        $this->mergeLeadgenSettings();
    }

    /**
     * Layer user customization (settings table, scope 'leadgen') on top of the
     * shipped config defaults: custom sources append to the source list, label
     * overrides merge over config's label maps. Guarded so a missing table
     * (fresh install mid-migrate) degrades to pure defaults instead of dying.
     *
     * Single-account merge for now: the app is effectively single-tenant, and
     * config() is process-global — a true per-account merge needs a request-
     * scoped resolver, which SaaS-someday can add without touching consumers.
     */
    private function mergeLeadgenSettings(): void
    {
        try {
            $data = \App\Models\Setting::query()->where('scope', 'leadgen')->value('data');
        } catch (Throwable) {
            return;
        }
        if (! is_array($data)) {
            return;
        }

        $custom = array_values(array_filter((array) ($data['custom_sources'] ?? []), 'is_string'));
        if ($custom !== []) {
            config(['leadgen.sources' => array_values(array_unique(array_merge(
                (array) config('leadgen.sources', []),
                $custom,
            )))]);
        }

        $labels = (array) ($data['source_labels'] ?? []);
        if ($labels !== []) {
            config(['leadgen.source_labels' => array_merge(
                (array) config('leadgen.source_labels', []),
                $labels,
            )]);
        }

        // Pipeline display labels: slugs stay fixed (they are attribution
        // history), but what a brand funnel is CALLED is presentation — the
        // demo account renames the funnels to its own fictional brands.
        foreach ((array) ($data['pipeline_labels'] ?? []) as $slug => $label) {
            if (is_string($slug) && is_string($label) && $label !== '' && config("leadgen.pipelines.{$slug}") !== null) {
                config(["leadgen.pipelines.{$slug}.label" => $label]);
            }
        }
    }
}
