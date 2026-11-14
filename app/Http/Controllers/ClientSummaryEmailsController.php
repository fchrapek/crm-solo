<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\Messaging\MailDelivery;
use App\Services\Messaging\MessageSettings;
use App\Services\Messaging\SummaryEmails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

/** A client's month-end summary email, the owner's only: the draft, its edits, the send and the month-close tick. */
final class ClientSummaryEmailsController extends Controller
{
    public function __construct(private readonly SummaryEmails $emails) {}

    public function show(Client $client, string $period, MessageSettings $settings, MailDelivery $delivery): JsonResponse
    {
        $email = $this->emails->find($client, $period);

        return response()->json([
            'email' => $email === null ? null : $this->emails->present($email),
            'candidates' => $this->emails->candidates($client),
            'templates' => $settings->emailTemplates($client->account_id),
            'delivery' => $delivery->mode(),
            'owner_email' => Auth::user()->email,
        ]);
    }

    public function store(Request $request, Client $client, string $period): RedirectResponse
    {
        $validated = $request->validate(['template' => ['nullable', 'string', 'max:64'], 'replace' => ['sometimes', 'boolean']]);
        $this->refusable(fn () => $this->emails->draft($client, $period, $validated['template'] ?? null, (bool) ($validated['replace'] ?? false)), 'template');

        return Redirect::back()->with('success', __('Summary email drafted'));
    }

    public function update(Request $request, Client $client, string $period): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => ['sometimes', 'string', 'max:200'],
            'body' => ['sometimes', 'string', 'max:20000'],
            'recipients' => ['sometimes', 'array', 'max:20'],
            'recipients.*' => ['string', 'max:255'],
            'bcc_owner' => ['sometimes', 'boolean'],
        ]);
        $email = $this->emails->find($client, $period) ?? abort(404);
        $this->refusable(fn () => $this->emails->update($email, $validated));

        return Redirect::back()->with('success', __('Summary email saved'));
    }

    public function send(Request $request, Client $client, string $period): RedirectResponse
    {
        $validated = $request->validate(['resend_unconfirmed' => ['sometimes', 'boolean']]);
        $email = $this->emails->find($client, $period) ?? abort(404);
        $this->refusable(fn () => $this->emails->send($email, Auth::user(), (bool) ($validated['resend_unconfirmed'] ?? false)));

        return Redirect::back()->with('success', __('Summary email queued'));
    }

    public function markSent(Client $client, string $period): RedirectResponse
    {
        $email = $this->emails->find($client, $period) ?? abort(404);
        $this->refusable(fn () => $this->emails->markSent($email, Auth::user()));

        return Redirect::back()->with('success', __('Summary email marked as sent'));
    }

    public function tick(Client $client, string $period): RedirectResponse
    {
        $email = $this->emails->find($client, $period) ?? abort(404);
        $this->refusable(fn () => $this->emails->tickStep($email));

        return Redirect::back();
    }
}
