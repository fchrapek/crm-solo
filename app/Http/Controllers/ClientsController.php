<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ClientsRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\ClientRetainer;
use App\Models\Invoice;
use App\Models\Task;
use App\Services\Agent\ClientBrief;
use App\Services\Clients\ClientBillingMode;
use App\Services\Clients\ClientBillingQuery;
use App\Services\Clients\ClientCreator;
use App\Services\Clients\ClientDetailsQuery;
use App\Services\Clients\ClientHeader;
use App\Services\Clients\ClientJournalQuery;
use App\Services\Clients\ClientListQuery;
use App\Services\Clients\ClientOverviewQuery;
use App\Services\Clients\ClientTasksQuery;
use App\Services\Clients\ClientViews;
use App\Services\Revenue\RevenueAggregator;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;
use Inertia\Inertia;
use Inertia\Response;
use Inertia\Support\Header;

final class ClientsController extends Controller
{
    /** The client record's tabs, in header order; the route accepts only these. */
    public const array TABS = ['przeglad', 'zadania', 'dziennik', 'rozliczenia', 'dane'];

    /**
     * Invoice rows shipped to the Invoices tab. At solo scale a client's whole
     * history fits well inside this, so the cap is a payload guard rather than
     * pagination — the UI says so when it bites.
     */
    private const int INVOICE_LIST_LIMIT = 100;

    public function index(): Response
    {
        return Inertia::render('clients/index', app(ClientListQuery::class)->props(
            Auth::user()->account_id,
            Request::only(['stage', 'cooperation_type', 'search', 'assignee', 'f']),
            Auth::id(),
        ));
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
        abort_if($tab === 'rozliczenia' && ! $client->account->hasFeature('billing'), 404);
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
                'rozliczenia' => app(ClientBillingQuery::class)->props($client, $this->queryString('miesiace'), $mode),
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

    public function edit(Client $client)
    {
        $projects = $client->projects()
            ->with([
                'tasks' => fn ($q) => $q->with('parentTask:id,name')->withCount('childTasks')->orderBy('position'),
                'repositories',
            ])
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn ($q) => $q->where(fn ($done) => $done->where('is_completed', true)->orWhereNotNull('finished_at')),
            ])
            ->get()
            ->map(fn ($project) => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'trello_url' => $project->trello_url,
                'is_private' => $project->trello_board_id === null,
                'trello_workspace' => $project->settings['trello_workspace'] ?? null,
                'trello_lists' => $project->settings['trello_lists'] ?? [],
                'trello_list_mapping' => $project->settings['trello_list_mapping'] ?? [],
                'custom_lanes' => $project->settings['custom_lanes'] ?? [],
                'include_in_month_close' => $project->include_in_month_close,
                'backup_path' => $project->backup_path,
                'tasks_count' => $project->tasks_count,
                'completed_tasks_count' => $project->completed_tasks_count,
                'repositories' => $project->repositories->map(fn ($repo) => [
                    'id' => $repo->id,
                    'name' => $repo->name,
                    'local_path' => $repo->local_path,
                    'remote_url' => $repo->remote_url,
                    'provider' => $repo->provider,
                ])->values(),
                'tasks' => $project->tasks->map(fn ($task) => [
                    'id' => $task->id,
                    'name' => $task->name,
                    'description' => $task->description,
                    'list_name' => $task->list_name,
                    'is_completed' => $task->is_completed,
                    'finished_at' => $task->finished_at?->toIso8601String(),
                    'archived_at' => $task->archived_at?->toIso8601String(),
                    'due_date' => $task->due_date?->toIso8601String(),
                    'labels' => $task->labels,
                    'trello_url' => $task->trello_url,
                    'source' => $task->source,
                    'has_trello_card' => $task->hasTrelloCard(),
                    'priority' => $task->priority,
                    'recurrence_period_days' => $task->recurrence_period_days,
                    'parent_task_id' => $task->parent_task_id,
                    'parent_task' => $task->parentTask ? ['id' => $task->parentTask->id, 'name' => $task->parentTask->name] : null,
                    'child_tasks_count' => (int) ($task->child_tasks_count ?? 0),
                    'is_overdue' => $task->isOverdue(),
                ]),
            ]);

        // Aktywność → Czas paginates server-side. `time_page` gets its own
        // param name so it can share the query string with the invoice filters.
        $timeEntries = $client->timeEntries()
            ->with(['project:id,name', 'task:id,name'])
            ->latest('start_time')
            ->paginate(25, ['*'], 'time_page')
            ->withQueryString()
            ->through(fn ($entry) => [
                'id' => $entry->id,
                'title' => $entry->title,
                'description' => $entry->description,
                'start_time' => $entry->start_time?->toIso8601String(),
                'end_time' => $entry->end_time?->toIso8601String(),
                'duration_minutes' => $entry->duration_minutes,
                'billable' => $entry->billable,
                'project_name' => $entry->project?->name,
                'source' => $entry->source,
                'is_running' => $entry->end_time === null,
                'task' => $entry->task ? ['id' => $entry->task->id, 'name' => $entry->task->name] : null,
                'tags' => $entry->tags,
            ]);

        // Candidate tasks for connecting a time entry to a task (Activity → Time).
        $clientTasks = Task::query()
            ->whereHas('project', fn ($q) => $q->where('client_id', $client->id))
            ->whereNull('archived_at')
            ->with('project:id,name')
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get()
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'name' => $task->name,
                'project_name' => $task->project?->name,
            ]);

        $trelloIntegration = Auth::user()->account->integrations()
            ->where('provider', 'trello')
            ->where('is_enabled', true)
            ->first();

        $reports = $client->reports()
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn ($report) => [
                'id' => $report->id,
                'period_type' => $report->period_type,
                'period_start' => $report->period_start->toDateString(),
                'period_end' => $report->period_end->toDateString(),
                'contracted_hours' => $report->contracted_hours !== null ? (float) $report->contracted_hours : null,
                'actual_hours' => (float) $report->actual_hours,
                'status' => $report->status,
                'generated_at' => $report->generated_at?->toIso8601String(),
                'finalized_at' => $report->finalized_at?->toIso8601String(),
            ]);

        $reportComposers = collect(app(\App\Services\Reports\ReportComposerRegistry::class)->all())
            ->map(fn ($composer) => [
                'key' => $composer->key(),
                'label' => $composer->label(),
            ])
            ->values();

        $retainers = $client->retainers()->get()->map(fn (ClientRetainer $retainer): array => $retainer->toPayload());

        $documents = $client->documents()
            ->get()
            ->map(fn ($doc) => [
                'id' => $doc->id,
                'url' => route('client-documents.show', [$client->id, $doc->id]),
                'original_name' => $doc->original_name,
                'mime' => $doc->mime,
                'size' => $doc->size,
                'category' => $doc->category,
                'label' => $doc->label,
                'created_at' => $doc->created_at->toIso8601String(),
            ]);

        $lifecycleEvents = $client->lifecycleEvents()
            ->with('user:id,first_name,last_name')
            ->get()
            ->map(fn ($event) => [
                'id' => $event->id,
                'from_stage' => $event->from_stage,
                'to_stage' => $event->to_stage,
                'note' => $event->note,
                'created_at' => $event->created_at->toIso8601String(),
                'user' => $event->user ? [
                    'id' => $event->user->id,
                    'name' => mb_trim($event->user->first_name.' '.$event->user->last_name),
                ] : null,
            ]);

        // Status is the ONLY payment signal used here. Infakt marks documents
        // paid without settling the amount fields - 770 of 796 rows carry
        // status=paid with paid_price=0, and 790 still show left_to_pay > 0 -
        // so anything derived from those columns would report nearly every
        // invoice as outstanding.
        $invoiceUrl = (string) config('services.infakt.invoice_url');
        $mapInvoice = fn (Invoice $invoice): array => [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'currency' => $invoice->currency ?? 'PLN',
            // Cast: grosze divided by 100 yields an int when the amount is
            // a whole zloty and a float otherwise, so the payload type
            // would flip between rows.
            'gross' => (float) ($invoice->gross_price / 100),
            'net' => (float) ($invoice->net_price / 100),
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'external_url' => $invoice->external_id
                ? str_replace('{id}', $invoice->external_id, $invoiceUrl)
                : null,
        ];

        $yearParam = (string) request()->query('invoice_year', (string) LocalCalendar::now()->year);
        $lastTwelve = $yearParam === 'last12';
        $invoiceYear = $yearParam === 'all' || $lastTwelve ? null : (int) $yearParam;
        $monthParam = request()->query('invoice_month');
        $invoiceMonth = ! $lastTwelve && is_numeric($monthParam) && (int) $monthParam >= 1 && (int) $monthParam <= 12 ? (int) $monthParam : null;

        // The rolling year counts by the books' date (sale date first) from the local today, as the revenue card does.
        $twelveTo = LocalCalendar::todayDate();
        $twelveFrom = $twelveTo->copy()->subYear();

        $filteredInvoices = fn () => $client->invoices()
            ->when($lastTwelve, fn ($q) => $q->whereBetween(DB::raw(Invoice::ACCRUAL_DATE_SQL), [$twelveFrom->toDateString(), $twelveTo->toDateString()]))
            ->when($invoiceYear !== null, fn ($q) => $q->whereYear('invoice_date', $invoiceYear))
            ->when($invoiceMonth !== null, fn ($q) => $q->whereMonth('invoice_date', $invoiceMonth));

        $invoiceTotal = $filteredInvoices()->count();
        $invoices = [
            'rows' => $filteredInvoices()->limit(self::INVOICE_LIST_LIMIT)->get()->map($mapInvoice)->values(),
            'total' => $invoiceTotal,
            'truncated' => $invoiceTotal > self::INVOICE_LIST_LIMIT,
        ];
        // The same window drives the revenue stat cards, so filtering the
        // table refilters the numbers above it. Null bounds = all-time.
        $periodFrom = null;
        $periodTo = null;
        if ($lastTwelve) {
            $periodFrom = $twelveFrom;
            $periodTo = $twelveTo;
        } elseif ($invoiceYear !== null) {
            $periodFrom = Carbon::create($invoiceYear, $invoiceMonth ?? 1, 1)->startOfDay();
            $periodTo = $invoiceMonth !== null ? $periodFrom->copy()->endOfMonth() : $periodFrom->copy()->endOfYear();
        }

        $invoiceFilters = [
            'year' => $lastTwelve ? 'last12' : ($invoiceYear ?? 'all'),
            'month' => $invoiceMonth,
            // Distinct years in PHP: YEAR() vs strftime() differs per driver
            // (tests run sqlite), and a client's invoice list is small.
            'years' => $client->invoices()
                ->whereNotNull('invoice_date')
                ->pluck('invoice_date')
                ->map(fn ($date) => (int) $date->format('Y'))
                ->unique()
                ->sortDesc()
                ->values(),
        ];

        return Inertia::render('clients/edit', [
            'client' => new ClientResource($client),
            'invoices' => $invoices,
            'invoiceFilters' => $invoiceFilters,
            'projects' => $projects,
            'timeEntries' => $timeEntries,
            'clientTasks' => $clientTasks,
            'lifecycleEvents' => $lifecycleEvents,
            'retainers' => $retainers,
            'documents' => $documents,
            'reports' => $reports,
            'reportComposers' => $reportComposers,
            'reportBaselineMarkdown' => $client->report_baseline_markdown,
            'revenue' => app(RevenueAggregator::class)->clientSummary(
                $client->account_id,
                $client->id,
                $periodFrom,
                $periodTo,
            ),
            'trelloEnabled' => ! config('app.demo') && ($trelloIntegration?->isConfigured() ?? false),
            // A board-linked project's sync, mapping and disconnect do not exist on the demo.
            'trelloActions' => ! config('app.demo'),
        ]);
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
        if (Request::boolean('delete_contacts')) {
            $client->contacts()->delete();
        }

        $client->delete();

        return Redirect::route('clients.index')->with('success', translate_with_gender('deleted', 'Client'));
    }

    public function restore(Client $client): RedirectResponse
    {
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
