<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientRetainer;
use App\Services\Clients\RetainerChangeRefused;
use App\Services\Clients\RetainerPositions;
use App\Services\Clients\RetainerTerms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

/** A client's abonament positions (`client_retainers`): the dialog's side of RetainerPositions. */
final class ClientRetainersController extends Controller
{
    public function __construct(
        private readonly RetainerPositions $positions,
        private readonly RetainerTerms $terms,
    ) {}

    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate(RetainerPositions::rules($client));

        try {
            $this->positions->create($client, $data);
        } catch (RetainerChangeRefused $refused) {
            return Redirect::back()->with('error', $refused->getMessage());
        }

        return Redirect::back()->with('success', __('Position saved.'));
    }

    public function update(Request $request, Client $client, ClientRetainer $retainer): RedirectResponse
    {
        $this->authorizeRetainer($client, $retainer);

        $data = $request->validate(RetainerPositions::rules($client));

        try {
            $this->positions->update($client, $retainer, $data);
        } catch (RetainerChangeRefused $refused) {
            return Redirect::back()->with('error', $refused->getMessage());
        }

        return Redirect::back()->with('success', __('Position updated.'));
    }

    public function destroy(Client $client, ClientRetainer $retainer): RedirectResponse
    {
        $this->authorizeRetainer($client, $retainer);

        $refusal = $this->terms->change($client, fn () => $retainer->delete());
        if ($refusal !== null) {
            return Redirect::back()->with('error', $refusal);
        }

        return Redirect::back()->with('success', __('Position removed.'));
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
