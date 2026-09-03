<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientLifecycleEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;

final class ClientLifecycleEventsController extends Controller
{
    /**
     * Add a free-form work-log note to the client's Activity timeline.
     * Stored as a same-stage lifecycle event (from_stage === to_stage), which
     * the table treats as a general timeline note — no stage transition. This
     * is the "write a session log in the CRM each time you work" surface.
     */
    public function store(Client $client): RedirectResponse
    {
        $data = Request::validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $client->lifecycleEvents()->create([
            'account_id' => $client->account_id,
            'user_id' => Auth::id(),
            'from_stage' => $client->lifecycle_stage,
            'to_stage' => $client->lifecycle_stage,
            'note' => mb_trim($data['note']),
            'created_at' => now(),
        ]);

        return Redirect::back()->with('success', __('Log entry added.'));
    }

    public function updateNote(int $event): RedirectResponse
    {
        $record = ClientLifecycleEvent::query()
            ->where('id', $event)
            ->where('account_id', Auth::user()->account_id)
            ->firstOrFail();

        $data = Request::validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $note = isset($data['note']) ? mb_trim((string) $data['note']) : null;
        if ($note === '') {
            $note = null;
        }

        $record->update(['note' => $note]);

        return Redirect::back()->with('success', __('Note saved.'));
    }
}
