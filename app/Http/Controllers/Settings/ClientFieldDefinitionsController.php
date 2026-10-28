<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ClientFieldDefinition;
use App\Services\Clients\Fields\ClientFieldDefinitions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;

/** Ustawienia / Pola klienta: add a field or change one, through the service the verbs use. */
final class ClientFieldDefinitionsController extends Controller
{
    public function update(Request $request, string $key, ClientFieldDefinitions $definitions): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(ClientFieldDefinition::TYPES)],
            'options' => ['nullable', 'array'],
            'options.*.key' => ['required', 'string', 'max:64'],
            'options.*.label' => ['required', 'string', 'max:100'],
            'required' => ['nullable', 'boolean'],
            'on_overview' => ['nullable', 'boolean'],
            'archived' => ['nullable', 'boolean'],
        ]);

        $this->refusable(fn () => $definitions->define(Auth::user()->account_id, $key, $validated), 'key');

        return Redirect::back()->with('success', __('Settings saved'));
    }
}
