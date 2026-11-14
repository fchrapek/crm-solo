<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ClientsRequest;
use App\Models\Client;
use App\Services\Agent\ClientBrief;
use App\Services\Clients\ClientBillingMode;
use App\Services\Clients\ClientBillingQuery;
use App\Services\Clients\ClientCreator;
use App\Services\Clients\ClientDetailsQuery;
use App\Services\Clients\ClientHeader;
use App\Services\Clients\ClientJournalQuery;
use App\Services\Clients\ClientListQuery;
use App\Services\Clients\ClientOverviewQuery;
use App\Services\Clients\ClientSwitcherQuery;
use App\Services\Clients\ClientTasksQuery;
use App\Services\Clients\ClientViews;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;
use Inertia\Inertia;
use Inertia\Response;
use Inertia\Support\Header;

final class ClientsController extends Controller
{
    /** The client record's tabs, in header order; the route accepts only these. */
    public const array TABS = ['przeglad', 'zadania', 'dziennik', 'rozliczenia', 'dane'];

    public function index(): Response
    {
        return Inertia::render('clients/index', app(ClientListQuery::class)->props(
            Auth::user()->account_id,
            Request::only(['stage', 'cooperation_type', 'search', 'assignee', 'f']),
            Auth::id(),
        ));
    }

    /** The short lists of the client switcher in the command bar. */
    public function switcher(ClientSwitcherQuery $query): JsonResponse
    {
        return response()->json($query->for(Auth::user()));
    }

    /** Creating a client is a dialog on Klienci now; the old address opens it there. */
    public function create(): RedirectResponse
    {
        return Redirect::route('clients.index', ['nowy' => 1]);
    }

    public function store(ClientsRequest $request, ClientCreator $creator): RedirectResponse
    {
        $client = $creator->create(Auth::user()->account_id, $request->validated())['client'];

        // A fresh record is filled in next, so it lands on its data rather than the list.
        return Redirect::route('clients.show', [$client, 'dane'])->with('success', translate_with_gender('created', 'Client'));
    }

    public function record(Client $client): RedirectResponse
    {
        return Redirect::route('clients.show', [$client, 'przeglad']);
    }

    public function show(Client $client, string $tab): Response
    {
        abort_if($tab === 'rozliczenia' && ! $client->account->shows('billing', Auth::user()), 404);
        $since = $this->openRecord($client);
        $mode = app(ClientBillingMode::class)->of($client);

        return Inertia::render('clients/show', [
            'tab' => $tab,
            // A closure, so Przegląd's SMS poll (a partial reload of overview) skips the header.
            'client' => fn (): array => app(ClientHeader::class)->props($client, $mode),
            ...match ($tab) {
                'przeglad' => app(ClientOverviewQuery::class)->props($client, $since, $mode),
                'dziennik' => app(ClientJournalQuery::class)->props($client, $since, $this->queryString('praca'), $this->queryString('wpisy')),
                'zadania' => app(ClientTasksQuery::class)->props($client, is_string(Request::query('wszystkie')) ? Request::query('wszystkie') : null, $mode),
                'rozliczenia' => app(ClientBillingQuery::class)->props(
                    $client,
                    $this->queryString('miesiace'),
                    $mode,
                    $this->queryString('miesiac'),
                    $this->queryString('invoice_year'),
                    $this->queryString('invoice_month'),
                ),
                'dane' => app(ClientDetailsQuery::class)->props($client),
                default => [],
            },
        ]);
    }

    /** What `crm brief --json` gives an agent, for pasting into an agent session. */
    public function agentContext(Client $client, ClientBrief $brief): JsonResponse
    {
        return response()->json([
            'context' => (string) json_encode($brief->for($client), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** The documents and settings page is retired: every part of it lives on the record's tabs, so the old address opens the record. */
    public function edit(Client $client): RedirectResponse
    {
        return Redirect::route('clients.show', [$client, 'przeglad']);
    }

    public function update(Client $client, ClientsRequest $request): RedirectResponse
    {
        $client->update($request->validated());

        return Redirect::back()->with('success', translate_with_gender('updated', 'Client'));
    }

    public function updateReportBaseline(\Illuminate\Http\Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'report_baseline_markdown' => ['nullable', 'string', 'max:20000'],
        ]);

        $baseline = isset($data['report_baseline_markdown']) ? mb_trim((string) $data['report_baseline_markdown']) : null;

        $client->update([
            'report_baseline_markdown' => $baseline === '' ? null : $baseline,
        ]);

        return Redirect::back()->with('success', __('Baseline scope saved.'));
    }

    public function destroy(Client $client): RedirectResponse
    {
        Gate::authorize('manage-account');

        if (Request::boolean('delete_contacts')) {
            $client->contacts()->delete();
        }

        $client->delete();

        return Redirect::route('clients.index')->with('success', translate_with_gender('deleted', 'Client'));
    }

    public function restore(Client $client): RedirectResponse
    {
        Gate::authorize('manage-account');

        $client->restore();

        return Redirect::back()->with('success', translate_with_gender('restored', 'Client'));
    }

    public function pin(Client $client): RedirectResponse
    {
        $data = Request::validate(['pinned' => ['required', 'boolean']]);
        $client->update(['is_pinned' => (bool) $data['pinned']]);

        return Redirect::back();
    }

    public function updateMonthCloseType(Client $client): RedirectResponse
    {
        $data = Request::validate([
            'month_close_type' => ['nullable', \Illuminate\Validation\Rule::in(Client::MONTH_CLOSE_TYPES)],
        ]);

        $next = $data['month_close_type'] ?? null;
        $updates = ['month_close_type' => $next];
        // Newly typed into the close => included by default ("Strona pod
        // opieką" implies month closing). An explicit uncheck survives
        // maintenance<->gig switches because only null->typed flips it.
        if ($next !== null && $client->month_close_type === null) {
            $updates['include_in_month_close'] = true;
        }
        $client->update($updates);

        return Redirect::back();
    }

    public function updateReportMode(Client $client): RedirectResponse
    {
        $data = Request::validate([
            'report_mode' => ['required', \Illuminate\Validation\Rule::in(Client::REPORT_MODES)],
        ]);

        $client->update(['report_mode' => $data['report_mode']]);

        return Redirect::back();
    }

    public function transitionStage(Client $client): RedirectResponse
    {
        $data = Request::validate([
            'stage' => ['required', 'string', \Illuminate\Validation\Rule::in(Client::LIFECYCLE_STAGES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $note = isset($data['note']) ? mb_trim((string) $data['note']) : null;
        if ($note === '') {
            $note = null;
        }

        if ($client->lifecycle_stage === $data['stage'] && $note === null) {
            return Redirect::back()->withErrors(['stage' => __('No change — pick a different stage or add a note.')]);
        }

        $client->transitionTo($data['stage'], $note, Auth::user());

        return Redirect::back()->with('success', __('Stage updated.'));
    }

    /** A page the user opens records the view; a partial reload or a prefetch only reads where "nowe" starts. */
    private function openRecord(Client $client): CarbonImmutable
    {
        $views = app(ClientViews::class);
        $user = Auth::user();

        return Request::hasHeader(Header::PARTIAL_COMPONENT) || Request::header('Purpose') === 'prefetch'
            ? $views->since($user, $client)
            : $views->open($user, $client);
    }

    private function queryString(string $key): ?string
    {
        $value = Request::query($key);

        return is_string($value) ? $value : null;
    }
}
