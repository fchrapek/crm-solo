<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

/**
 * The settings hub: one page, sectioned per area, reachable from the sidebar
 * (findability) and from each section's header gear (discoverability). Shipped
 * defaults come from config/*.php; only user customization is stored (settings
 * table) and merged over the defaults at boot.
 */
final class SettingsController extends Controller
{
    public function index()
    {
        // Defaults straight from the shipped config FILE — runtime config has
        // the user's customization already merged in, so reading config() here
        // would present custom entries as if they were defaults.
        $shipped = require config_path('leadgen.php');
        $stored = $this->leadgenSettings();

        return Inertia::render('settings/index', [
            'leadgen' => [
                'default_sources' => array_values((array) ($shipped['sources'] ?? [])),
                'custom_sources' => array_values((array) ($stored['custom_sources'] ?? [])),
                'source_labels' => (object) ($stored['source_labels'] ?? []),
            ],
        ]);
    }

    public function updateLeadgen(Request $request): RedirectResponse
    {
        $shipped = require config_path('leadgen.php');
        $defaults = array_values((array) ($shipped['sources'] ?? []));

        $validated = $request->validate([
            'custom_sources' => ['array'],
            'custom_sources.*' => ['string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'distinct'],
            'source_labels' => ['array'],
            'source_labels.*' => ['string', 'max:100'],
        ]);

        // Custom slugs may not shadow shipped defaults — a duplicate would be
        // two list entries for one stored value.
        $custom = array_values(array_diff($validated['custom_sources'] ?? [], $defaults));

        // Keep only labels that refer to a known slug (default or custom).
        $labels = array_intersect_key(
            $validated['source_labels'] ?? [],
            array_flip([...$defaults, ...$custom]),
        );

        Setting::updateOrCreate(
            ['account_id' => Auth::user()->account_id, 'scope' => 'leadgen'],
            ['data' => ['custom_sources' => $custom, 'source_labels' => $labels]],
        );

        return Redirect::back()->with('success', __('Settings saved'));
    }

    /** @return array<string, mixed> */
    private function leadgenSettings(): array
    {
        $data = Setting::query()
            ->where('account_id', Auth::user()->account_id)
            ->where('scope', 'leadgen')
            ->value('data');

        return is_array($data) ? $data : [];
    }
}
