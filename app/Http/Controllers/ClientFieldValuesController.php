<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use App\Services\Agent\AgentRefusal;
use App\Services\Clients\Fields\ClientFieldWriter;
use App\Services\Messaging\MessageSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;

/** Klient / Dane, Pola klienta: the fields, the assignee and the opt-out, through the writer the verbs use. */
final class ClientFieldValuesController extends Controller
{
    public function update(Request $request, Client $client, ClientFieldWriter $writer, MessageSettings $messages): RedirectResponse
    {
        $validated = $request->validate([
            'values' => ['nullable', 'array'],
            'assignee_id' => ['nullable', 'integer'],
            'sms_opt_out' => ['nullable', 'boolean'],
        ]);
        $actor = Auth::user();

        // One transaction, the opt-out and assignee first, so a message the field change queues is never sent on the old ones.
        $this->refusable(fn () => DB::transaction(function () use ($validated, $request, $client, $writer, $messages, $actor): void {
            if (array_key_exists('sms_opt_out', $validated) && $validated['sms_opt_out'] !== null) {
                $messages->optOut($client, $validated['sms_opt_out']);
            }
            if ($request->has('assignee_id')) {
                $assignee = $validated['assignee_id'] === null ? null
                    : (User::query()->where('account_id', $client->account_id)->find($validated['assignee_id'])
                        ?? throw AgentRefusal::invalid(__('That user is no longer on the account.'), ['field' => 'assignee_id']));
                $writer->assign($client, $assignee, $actor);
            }
            if (($validated['values'] ?? []) !== []) {
                $writer->set($client, $validated['values'], $actor);
            }
        }));

        return Redirect::back()->with('success', __('Saved'));
    }
}
