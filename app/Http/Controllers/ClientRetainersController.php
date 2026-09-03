<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientRetainer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;

/**
 * Manages a client's abonament positions (`client_retainers`). A client can
 * carry many active positions, each optionally scoped to a project, each with
 * its own hours/fee/overage/rollover and an invoice grouping. Positions are
 * independent — adding one never closes the others (unlike the old
 * single-retainer-per-client timeline).
 */
final class ClientRetainersController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $this->validatePosition($request, $client);

        $client->retainers()->create([
            'account_id' => $client->account_id,
            'project_id' => $data['project_id'] ?? null,
            'label' => $data['label'] ?? null,
            'description' => $data['description'] ?? null,
            'monthly_hours' => $data['monthly_hours'],
            'monthly_fee' => $data['monthly_fee'] ?? null,
            'overage_hourly_rate' => $data['overage_hourly_rate'] ?? null,
            'rollover_cap_hours' => $data['rollover_cap_hours'] ?? null,
            'invoice_group' => $data['invoice_group'] ?? 1,
            'vat_symbol' => $data['vat_symbol'] ?? '23',
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
            'currency' => $data['currency'] ?? $client->currency ?? 'PLN',
            'effective_from' => Carbon::parse($data['effective_from'] ?? now()->toDateString())->startOfDay(),
            'effective_to' => null,
            'notes' => $data['notes'] ?? null,
        ]);

        // A client with an abonament position defaults into the maintenance
        // close cohort (unless already tagged otherwise).
        if ($client->month_close_type === null) {
            $client->update(['month_close_type' => Client::MONTH_CLOSE_TYPES[0]]);
        }

        return Redirect::back()->with('success', __('Position saved.'));
    }

    public function update(Request $request, Client $client, ClientRetainer $retainer): RedirectResponse
    {
        $this->authorizeRetainer($client, $retainer);

        $data = $this->validatePosition($request, $client);

        $retainer->update([
            'project_id' => $data['project_id'] ?? null,
            'label' => $data['label'] ?? null,
            'description' => $data['description'] ?? null,
            'monthly_hours' => $data['monthly_hours'],
            'monthly_fee' => $data['monthly_fee'] ?? null,
            'overage_hourly_rate' => $data['overage_hourly_rate'] ?? null,
            'rollover_cap_hours' => $data['rollover_cap_hours'] ?? null,
            'invoice_group' => $data['invoice_group'] ?? 1,
            'vat_symbol' => $data['vat_symbol'] ?? '23',
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
            'currency' => $data['currency'] ?? $retainer->currency,
            'effective_from' => isset($data['effective_from'])
                ? Carbon::parse($data['effective_from'])->startOfDay()
                : $retainer->effective_from,
            'notes' => $data['notes'] ?? null,
        ]);

        return Redirect::back()->with('success', __('Position updated.'));
    }

    public function destroy(Client $client, ClientRetainer $retainer): RedirectResponse
    {
        $this->authorizeRetainer($client, $retainer);

        $retainer->delete();

        return Redirect::back()->with('success', __('Position removed.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePosition(Request $request, Client $client): array
    {
        return $request->validate([
            'project_id' => [
                'nullable', 'integer',
                Rule::exists('projects', 'id')->where(fn ($q) => $q->where('client_id', $client->id)),
            ],
            'label' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'monthly_hours' => ['required', 'numeric', 'min:0', 'max:744'],
            'monthly_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'overage_hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'rollover_cap_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'invoice_group' => ['nullable', 'integer', 'min:1', 'max:50'],
            'vat_symbol' => ['nullable', 'string', 'max:8'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'effective_from' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function authorizeRetainer(Client $client, ClientRetainer $retainer): void
    {
        abort_unless(
            $retainer->client_id === $client->id
                && $retainer->account_id === Auth::user()->account_id,
            404,
        );
    }
}
