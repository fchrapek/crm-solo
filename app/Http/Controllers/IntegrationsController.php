<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\SyncTrelloProjectsJob;
use App\Models\Integration;
use App\Services\Integrations\InfaktApiException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** The writes behind Ustawienia · Integracje. */
final class IntegrationsController extends Controller
{
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

        $integration = Auth::user()->account->integrations()->firstOrNew(['provider' => $provider]);
        $secretFields = Integration::secretFields($provider);

        // Only the provider's declared settings keys merge into what is stored;
        // credentials only come from their own fields.
        $settings = [
            ...($integration->settings ?? []),
            ...Arr::only($validated['settings'] ?? [], Integration::settingsFields($provider)),
        ];

        // A credential field left blank keeps the stored value.
        foreach ($secretFields as $field) {
            $value = $validated[$field] ?? null;
            if (! is_string($value) || mb_trim($value) === '') {
                continue;
            }

            if ($field === 'api_key') {
                $integration->api_key = $value;
            } else {
                $settings[$field] = $value;
            }
        }

        $integration->fill([
            'is_enabled' => $validated['is_enabled'] ?? false,
            'settings' => $settings,
            'last_sync_error' => null,
        ])->save();

        return back()->with('success', __('Integration updated.'));
    }

    /**
     * The explicit way to clear credentials, since a blank field on save keeps
     * them: removes every credential of the provider (unreadable ones too),
     * turns the integration off, and keeps synced data and other settings.
     */
    public function disconnect(string $provider): RedirectResponse
    {
        if (! array_key_exists($provider, Integration::PROVIDERS)) {
            abort(404);
        }

        $integration = Auth::user()->account->integrations()->where('provider', $provider)->first();

        if ($integration !== null) {
            $settings = $integration->settings ?? [];
            foreach (Integration::secretFields($provider) as $field) {
                if ($field !== 'api_key') {
                    // An explicit null removes the key, including a value this APP_KEY cannot read.
                    $settings[$field] = null;
                }
            }

            $integration->api_key = null;
            $integration->fill([
                'is_enabled' => false,
                'settings' => $settings,
                'last_sync_error' => null,
            ])->save();
        }

        return back()->with('success', __('Integration disconnected.'));
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

            $conflicts = $stats['conflicts'];
            if ($conflicts === []) {
                return back()->with('success', $message);
            }

            // Conflicts need a human, so they come back as an error with the first few named.
            $shown = array_slice($conflicts, 0, 3);
            $rest = count($conflicts) - count($shown);

            return back()->with('error', implode(' ', array_filter([
                $message.'.',
                trans_choice(':count Infakt client was not linked because its NIP belongs to a CRM client linked to another Infakt client:', count($conflicts)),
                implode(' ', $shown),
                $rest > 0 ? trans_choice('(and :count more, listed on the integration page)', $rest) : null,
            ])));
        }

        if ($provider === 'trello') {
            SyncTrelloProjectsJob::dispatch($integration, $uuid);

            return back()
                ->with('success', __('Project sync started. This may take a few minutes.'))
                ->with('syncUuid', $uuid);
        }

        return back()->with('success', __('Sync started.'));
    }
}
