<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\SyncClockifyTimeEntriesJob;
use App\Jobs\SyncTrelloProjectsJob;
use App\Models\Integration;
use App\Services\Integrations\InfaktApiException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class IntegrationsController extends Controller
{
    public function index(): Response
    {
        $account = Auth::user()->account;

        $providers = collect(Integration::PROVIDERS)->map(function ($config, $provider) use ($account) {
            $integration = $account->integrations()->where('provider', $provider)->first();

            return [
                'provider' => $provider,
                'name' => $config['name'],
                'description' => $config['description'],
                'website' => $config['website'],
                'features' => $config['features'],
                'auth_type' => $config['auth_type'] ?? 'api_key',
                'is_enabled' => $integration?->is_enabled ?? false,
                'is_configured' => $integration?->isConfigured() ?? false,
                'last_synced_at' => $integration?->last_synced_at,
            ];
        })->values();

        return Inertia::render('integrations/index', [
            'integrations' => $providers,
        ]);
    }

    public function edit(string $provider): Response
    {
        if (! array_key_exists($provider, Integration::PROVIDERS)) {
            abort(404);
        }

        $account = Auth::user()->account;
        $integration = $account->integrations()->where('provider', $provider)->first();
        $config = Integration::PROVIDERS[$provider];

        $settings = $integration?->settings ?? [];

        return Inertia::render('integrations/edit', [
            'integration' => [
                'provider' => $provider,
                'name' => $config['name'],
                'description' => $config['description'],
                'website' => $config['website'],
                'features' => $config['features'],
                'auth_type' => $config['auth_type'] ?? 'api_key',
                'is_enabled' => $integration?->is_enabled ?? false,
                'has_api_key' => $integration?->hasValidApiKey() ?? false,
                'is_configured' => $integration?->isConfigured() ?? false,
                'last_synced_at' => $integration?->last_synced_at,
                'last_sync_error' => $integration?->last_sync_error,
                'has_trello_api_key' => ! empty($settings['trello_api_key']),
                'connected_email' => $settings['email'] ?? null,
            ],
        ]);
    }

    public function update(Request $request, string $provider): RedirectResponse
    {
        if (! array_key_exists($provider, Integration::PROVIDERS)) {
            abort(404);
        }

        $validated = $request->validate([
            'api_key' => ['nullable', 'string', 'max:500'],
            'is_enabled' => ['boolean'],
            'settings' => ['nullable', 'array'],
            'trello_api_key' => ['nullable', 'string', 'max:500'],
        ]);

        $account = Auth::user()->account;

        $settings = $validated['settings'] ?? [];
        if ($provider === 'trello') {
            $existingIntegration = $account->integrations()->where('provider', $provider)->first();
            $trelloSettings = [];
            if (! empty($validated['trello_api_key'])) {
                $trelloSettings['trello_api_key'] = $validated['trello_api_key'];
            }
            if ($trelloSettings) {
                $settings = array_merge($existingIntegration?->settings ?? [], $settings, $trelloSettings);
            }
        }

        $account->integrations()->updateOrCreate(
            ['provider' => $provider],
            [
                'is_enabled' => $validated['is_enabled'] ?? false,
                'settings' => $settings,
                ...($validated['api_key'] ? ['api_key' => $validated['api_key']] : []),
                'last_sync_error' => null,
            ]
        );

        return redirect()->route('integrations.edit', $provider)
            ->with('success', __('Integration updated.'));
    }

    public function sync(string $provider): RedirectResponse
    {
        if (! array_key_exists($provider, Integration::PROVIDERS)) {
            abort(404);
        }

        $account = Auth::user()->account;
        $integration = $account->integrations()->where('provider', $provider)->first();

        if (! $integration || ! $integration->is_enabled || ! $integration->isConfigured()) {
            return back()->with('error', __('Integration is not configured properly.'));
        }

        $integration->update(['last_sync_error' => null]);

        $uuid = Str::uuid()->toString();

        if ($provider === 'infakt') {
            $service = new \App\Services\Integrations\InfaktService($integration);

            try {
                $stats = $service->syncClients($account->id);
            } catch (InfaktApiException $e) {
                return back()->with('error', $e->userMessage());
            }

            $message = __('Sync completed. Created: :created, Updated: :updated', [
                'created' => $stats['created'],
                'updated' => $stats['updated'],
            ]);

            return back()->with('success', $message);
        }

        if ($provider === 'trello') {
            SyncTrelloProjectsJob::dispatch($integration, $uuid);

            return back()
                ->with('success', __('Project sync started. This may take a few minutes.'))
                ->with('syncUuid', $uuid);
        }

        if ($provider === 'clockify') {
            SyncClockifyTimeEntriesJob::dispatch($integration, null, $uuid);

            return back()
                ->with('success', __('Time entries sync started.'))
                ->with('syncUuid', $uuid);
        }

        return back()->with('success', __('Sync started.'));
    }
}
