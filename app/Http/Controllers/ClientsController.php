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
use App\Services\Clients\MonthCloseModule;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ClientsController extends Controller
{
    /** The client record's tabs, in header order; the route accepts only these. */
    public const array TABS = ['overview', 'tasks', 'journal', 'billing', 'details'];

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

    /** Creating a client is a dialog on Clients now; the old address opens it there. */
    public function create(): RedirectResponse
    {
        return Redirect::route('clients.index', ['new' => 1]);
    }

    public function store(ClientsRequest $request, ClientCreator $creator): RedirectResponse
    {
        $client = $creator->create(Auth::user()->account_id, $request->validated())['client'];

        // A fresh record is filled in next, so it lands on its data rather than the list.
        return Redirect::route('clients.show', [$client, 'details'])->with('success', translate_with_gender('created', 'Client'));
    }

    public function record(Client $client): RedirectResponse
    {
        return Redirect::route('clients.show', [$client, 'overview']);
    }

    public function show(Client $client, string $tab): Response
    {
        abort_if($tab === 'billing' && ! $client->account->shows('billing', Auth::user()), 404);
        // The page reports its opening (view()) as of read_at, so a prefetch, a partial reload and a copy served from the cache read alike.
        $readAt = CarbonImmutable::now('UTC')->startOfSecond();
        $since = app(ClientViews::class)->since(Auth::user(), $client);
        $mode = app(ClientBillingMode::class)->of($client);

        return Inertia::render('clients/show', [
            'tab' => $tab,
            'read_at' => $readAt->getTimestamp(),
            // A closure, so Overview's SMS poll (a partial reload of overview) skips the header.
            'client' => fn (): array => app(ClientHeader::class)->props($client, $mode),
            ...match ($tab) {
                'overview' => app(ClientOverviewQuery::class)->props($client, $since, $mode),
                'journal' => app(ClientJournalQuery::class)->props($client, $since, $this->queryString('work'), $this->queryString('notes'), $this->queryString('view')),
                'tasks' => [
                    ...app(ClientTasksQuery::class)->props($client, is_string(Request::query('expand')) ? Request::query('expand') : null, $mode),
                    'parent_options' => Inertia::optional(fn (): array => app(ClientTasksQuery::class)->parentOptions($client)),
                ],
                'billing' => app(ClientBillingQuery::class)->props(
                    $client,
                    $this->queryString('ledger'),
                    $mode,
                    $this->queryString('month'),
                    $this->queryString('invoice_year'),
                    $this->queryString('invoice_month'),
                ),
                'details' => app(ClientDetailsQuery::class)->props($client),
                default => [],
            },
        ]);
    }

    /**
     * The record page reports each opening, also one shown from the prefetch cache without a request, as of when its
     * data was read: however long ago, as a page restored from history reports its own reading and a view never moves
     * back, but never ahead of this server's clock by more than READ_AT_SKEW_SECONDS.
     */
    public function view(HttpRequest $request, Client $client, ClientViews $views): HttpResponse
    {
        $now = CarbonImmutable::now('UTC')->getTimestamp();
        $validated = $request->validate([
            'read_at' => ['required', 'integer', 'max:'.($now + ClientViews::READ_AT_SKEW_SECONDS), 'min:'.($client->created_at?->getTimestamp() ?? 0)],
        ]);
        $views->open(Auth::user(), $client, CarbonImmutable::createFromTimestamp((int) $validated['read_at'], 'UTC'));

        return response()->noContent();
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
        return Redirect::route('clients.show', [$client, 'overview']);
    }

    public function update(Client $client, ClientsRequest $request): RedirectResponse
    {
        $on = app(MonthCloseModule::class)->enrol($client, fn () => $client->update($request->validated()));

        return Redirect::back()->with('success', $on ? __('month_close_module_turned_on') : translate_with_gender('updated', 'Client'));
    }

    public function updateReportBaseline(HttpRequest $request, Client $client): RedirectResponse
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

        $client->delete();

        return Redirect::route('clients.index')->with('success', __('Client archived.'));
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
        // Newly typed into the close => included by default (Maintenance
        // site implies month closing). An explicit uncheck survives
        // maintenance<->gig switches because only null->typed flips it.
        if ($next !== null && $client->month_close_type === null) {
            $updates['include_in_month_close'] = true;
        }
        $on = app(MonthCloseModule::class)->enrol($client, fn () => $client->update($updates));

        return $on ? Redirect::back()->with('success', __('month_close_module_turned_on')) : Redirect::back();
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

    private function queryString(string $key): ?string
    {
        $value = Request::query($key);

        return is_string($value) ? $value : null;
    }
}
