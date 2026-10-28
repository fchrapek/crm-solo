<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Support\LegalName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kontakty: the list, with create and edit as a dialog over it. `?kontakt=new`
 * opens the empty form and `?kontakt={id}` that contact, which is how the old
 * create and edit URLs and the Klient Dane rows arrive.
 */
final class ContactsController extends Controller
{
    public function index(): Response
    {
        $account = Auth::user()->account;
        $search = mb_trim((string) Request::input('search', ''));
        $opened = (string) Request::input('kontakt', '');
        $trashed = Request::input('trashed') === 'with' ? 'with' : null;

        return Inertia::render('contacts/index', [
            'filters' => ['search' => $search, 'trashed' => $trashed],
            'contacts' => $account->contacts()
                ->with('client:id,name')
                ->orderByName()
                ->filter(['search' => $search, 'trashed' => $trashed], $account->id)
                ->paginate()
                ->withQueryString()
                ->through(fn (Contact $contact): array => [
                    ...(new ContactResource($contact))->resolve(),
                    'name' => $contact->name,
                    'client' => $contact->client === null ? null : ['id' => $contact->client->id, 'name' => LegalName::short($contact->client->name)],
                ]),
            'clients' => $account->clients()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($client): array => ['id' => $client->id, 'name' => LegalName::short($client->name)])->values(),
            'opened' => match (true) {
                $opened === 'new' => 'new',
                ctype_digit($opened) => new ContactResource((new Contact)->resolveRouteBinding($opened)),
                default => null,
            },
        ]);
    }

    public function create(): RedirectResponse
    {
        return Redirect::route('contacts.index', ['kontakt' => 'new']);
    }

    public function store(ContactRequest $request): RedirectResponse
    {
        Auth::user()->account->contacts()->create($request->validated());

        return Redirect::back()->with('success', translate_with_gender('created', 'Contact'));
    }

    public function edit(Contact $contact): RedirectResponse
    {
        return Redirect::route('contacts.index', ['kontakt' => $contact->id]);
    }

    public function update(Contact $contact, ContactRequest $request): RedirectResponse
    {
        $contact->update($request->validated());

        return Redirect::back()->with('success', translate_with_gender('updated', 'Contact'));
    }

    public function destroy(Contact $contact): RedirectResponse
    {
        $contact->delete();

        return Redirect::back()->with('success', translate_with_gender('deleted', 'Contact'));
    }

    public function restore(Contact $contact): RedirectResponse
    {
        $contact->restore();

        return Redirect::back()->with('success', translate_with_gender('restored', 'Contact'));
    }
}
