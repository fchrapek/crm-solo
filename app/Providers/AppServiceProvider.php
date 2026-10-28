<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AgentToken;
use App\Models\Client;
use App\Models\ClientLifecycleEvent;
use App\Models\Contact;
use App\Models\DayPick;
use App\Models\Lead;
use App\Models\LeadStageEvent;
use App\Models\MonthCloseRun;
use App\Models\MonthCloseStep;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskBrief;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Agent\AgentCallContext;
use App\Services\Agent\AgentIdentityResolver;
use App\Services\Agent\AgentWriteRecorder;
use App\Services\Agent\OwnerIdentityResolver;
use App\Services\AI\AIProviderInterface;
use App\Services\AI\AIServiceManager;
use App\Services\Reports\Composers\AiNarrativeComposer;
use App\Services\Reports\Composers\StructuredListComposer;
use App\Services\Reports\ReportComposerRegistry;
use App\Services\TaskSources\Providers\TrelloTaskSourceProvider;
use App\Services\TaskSources\TaskSourceRegistry;
use App\Support\DemoWriteBudget;
use App\Support\UploadLimits;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

final class AppServiceProvider extends ServiceProvider
{
    public const string HOME = '/';

    public static function wipesForbidden(): bool
    {
        return app()->isProduction() && ! config('app.demo') && ! config('app.demo_reset_allowed');
    }

    public function register(): void
    {
        JsonResource::withoutWrapping();

        $this->app->singleton(AIServiceManager::class);
        $this->app->bind(AIProviderInterface::class, fn ($app) => $app->make(AIServiceManager::class)->getProvider());

        // Local transports carry no credentials, so agent writes are attributed
        // to the account owner. A hosted transport rebinds this to derive the
        // identity from the request token.
        $this->app->bind(AgentIdentityResolver::class, OwnerIdentityResolver::class);

        // Scoped: an Octane request or a queued job never inherits another's open agent call.
        $this->app->scoped(AgentCallContext::class);

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
        // db:wipe, migrate:fresh/refresh/reset are refused on a production install that holds real data;
        // only the demo and a fictional-data staging stack (their own reset path) may wipe.
        DB::prohibitDestructiveCommands(self::wipesForbidden());

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

        RateLimiter::for('uploads', UploadLimits::limit(...));
        RateLimiter::for('demo-writes', DemoWriteBudget::limit(...));
        // Every demo visitor is the same user, so the default per-user key would be one shared bucket.
        RateLimiter::for('waitlist', fn (Request $request): array => [
            Limit::perMinute((int) config('waitlist.per_minute'))->by('waitlist-m|'.$request->ip()),
            Limit::perHour((int) config('waitlist.per_hour'))->by('waitlist-h|'.$request->ip()),
        ]);

        // Profile saves check the current password, so they get a per-user budget against guessing it.
        RateLimiter::for('password-change', fn (Request $request): Limit => Limit::perMinute(5)->by('pw|'.$request->user()?->id));

        // Agent tokens: one per agent, account-bound, per-token budget across MCP and the verb endpoint.
        Sanctum::usePersonalAccessTokenModel(AgentToken::class);
        Gate::define('manage-agent-tokens', fn (User $user): bool => (bool) $user->owner);
        RateLimiter::for('agent', fn (Request $request): Limit => Limit::perMinute((int) config('agent.per_minute'))
            ->by('agent|'.($request->attributes->get('agent_token_id') ?? $request->ip())));

        foreach ([TimeEntry::class, ClientLifecycleEvent::class, Task::class, TaskBrief::class, Lead::class, LeadStageEvent::class, MonthCloseRun::class, MonthCloseStep::class, Project::class, Client::class, Contact::class, DayPick::class] as $model) {
            $model::observe(AgentWriteRecorder::class);
        }
    }
}
