<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ClientsRequest;
use App\Http\Resources\ClientCollection;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Task;
use App\Services\Revenue\RevenueAggregator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;
use Inertia\Inertia;

final class ClientsController extends Controller
{
    /**
     * Invoice rows shipped to the Invoices tab. At solo scale a client's whole
     * history fits well inside this, so the cap is a payload guard rather than
     * pagination — the UI says so when it bites.
     */
    private const int INVOICE_LIST_LIMIT = 100;

    public function index()
    {
        // Lifecycle-stage filter. Defaults to 'active' so the list opens on
        // live clients; 'all' is the explicit escape hatch (any other value
        // falls back to the default rather than returning an empty list).
        $stage = (string) Request::input('stage', 'active');
        if (! in_array($stage, [...Client::LIFECYCLE_STAGES, 'all'], true)) {
            $stage = 'active';
        }

        $filters = [
            ...Request::only(['search', 'trashed', 'month_close_type']),
            'stage' => $stage,
        ];

        return Inertia::render('clients/index', [
            'filters' => $filters,
            'clients' => new ClientCollection(
                Auth::user()->account->clients()
                    ->withCount('contacts')
                    ->orderByDesc('is_pinned')
                    ->orderBy('name')
                    ->filter($filters, Auth::user()->account_id)
                    ->paginate()
                    ->withQueryString()
            ),
        ]);
    }

    public function create()
    {
        return Inertia::render('clients/create');
    }

    public function store(ClientsRequest $request): RedirectResponse
    {
        $client = Auth::user()->account->clients()->create($request->validated());

        Project::create([
            'account_id' => $client->account_id,
            'client_id' => $client->id,
            'name' => 'General',
        ]);

        // Land on the new client's edit page, not the index — new clients
        // start at lifecycle_stage 'prospect' while the index defaults to the
        // 'active' filter, so a redirect there makes the fresh client
        // invisibly vanish.
        return Redirect::route('clients.edit', $client)->with('success', translate_with_gender('created', 'Client'));
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
                'tasks as completed_tasks_count' => fn ($q) => $q->where('is_completed', true),
                'tasks as active_agent_tasks_count' => fn ($q) => $q->whereNotNull('cli')->where('agent_lane', '!=', 'done')->whereNull('archived_at'),
            ])
            ->get()
            ->map(fn ($project) => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'trello_url' => $project->trello_url,
                'clockify_project_id' => $project->clockify_project_id,
                'is_private' => $project->trello_board_id === null,
                'trello_workspace' => $project->settings['trello_workspace'] ?? null,
                'trello_lists' => $project->settings['trello_lists'] ?? [],
                'trello_list_mapping' => $project->settings['trello_list_mapping'] ?? [],
                'custom_lanes' => $project->settings['custom_lanes'] ?? [],
                'preview_command' => $project->preview_command,
                'preview_working_dir' => $project->preview_working_dir,
                'preview_url' => $project->preview_url,
                'include_in_month_close' => $project->include_in_month_close,
                'backup_path' => $project->backup_path,
                'tasks_count' => $project->tasks_count,
                'completed_tasks_count' => $project->completed_tasks_count,
                'active_agent_tasks_count' => $project->active_agent_tasks_count,
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
                    'archived_at' => $task->archived_at?->toIso8601String(),
                    'due_date' => $task->due_date?->toIso8601String(),
                    'labels' => $task->labels,
                    'trello_url' => $task->trello_url,
                    'source' => $task->source,
                    'priority' => $task->priority,
                    'recurrence_period_days' => $task->recurrence_period_days,
                    'parent_task_id' => $task->parent_task_id,
                    'parent_task' => $task->parentTask ? ['id' => $task->parentTask->id, 'name' => $task->parentTask->name] : null,
                    'child_tasks_count' => (int) ($task->child_tasks_count ?? 0),
                    'cli' => $task->cli,
                    'agent_lane' => $task->agent_lane,
                    'is_overdue' => $task->due_date && $task->due_date->isPast() && ! $task->is_completed,
                ]),
            ]);

        $agentTasks = Task::onAgentBoard()
            ->whereHas('project', fn ($q) => $q->where('client_id', $client->id))
            ->with(['project:id,name', 'parentTask:id,name'])
            ->withCount('childTasks')
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'name' => $task->name,
                'description' => $task->description,
                'list_name' => $task->list_name,
                'due_date' => $task->due_date?->toIso8601String(),
                'priority' => $task->priority,
                'parent_task_id' => $task->parent_task_id,
                'parent_task' => $task->parentTask ? ['id' => $task->parentTask->id, 'name' => $task->parentTask->name] : null,
                'child_tasks_count' => (int) ($task->child_tasks_count ?? 0),
                'source' => $task->source,
                'type' => $task->type,
                'agent_lane' => $task->agent_lane ?? Task::AGENT_LANE_BACKLOG,
                'cli' => $task->cli,
                'session_branch_name' => $task->cli !== null ? $task->sessionBranchName() : null,
                'session_port' => $task->session_port,
                'session_attention_at' => $task->session_attention_at?->toIso8601String(),
                'issue_url' => $task->issue_url,
                'project' => $task->project ? ['id' => $task->project->id, 'name' => $task->project->name] : null,
                'trello_url' => $task->trello_url,
                'updated_at' => $task->updated_at?->toIso8601String(),
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
                'is_in_clockify' => $entry->clockify_entry_id !== null,
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

        $retainers = $client->retainers()
            ->get()
            ->map(fn ($retainer) => [
                'id' => $retainer->id,
                'project_id' => $retainer->project_id,
                'label' => $retainer->label,
                'description' => $retainer->description,
                'monthly_hours' => (float) $retainer->monthly_hours,
                'monthly_fee' => $retainer->monthly_fee !== null ? (float) $retainer->monthly_fee : null,
                'overage_hourly_rate' => $retainer->overage_hourly_rate !== null ? (float) $retainer->overage_hourly_rate : null,
                'rollover_cap_hours' => $retainer->rollover_cap_hours !== null ? (float) $retainer->rollover_cap_hours : null,
                'invoice_group' => $retainer->invoice_group,
                'vat_symbol' => $retainer->vat_symbol,
                'is_active' => $retainer->is_active,
                'sort_order' => $retainer->sort_order,
                'currency' => $retainer->currency,
                'effective_from' => $retainer->effective_from->toDateString(),
                'effective_to' => $retainer->effective_to?->toDateString(),
                'notes' => $retainer->notes,
            ]);

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

        // The Przegląd glance: logged time this month vs the retainer limit,
        // plus the newest report's state. Deliberately simpler than the report
        // hours-bank math (which is reportable-only + opening balance) — this
        // answers "how loaded is this client right now", the report stays the
        // billing truth.
        $monthStart = now()->startOfMonth();
        $monthMinutes = (int) $client->timeEntries()
            ->where('start_time', '>=', $monthStart)
            ->sum('duration_minutes');
        $latestReport = $client->reports()
            ->orderByDesc('period_start')
            ->first();
        // Faktury: the last few Infakt documents plus a 12-month total, so the
        // overview answers "is this client billing" alongside "is it busy".
        //
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

        // The overview card is a glance (3 rows + a link); the Invoices tab
        // holds the full list. Same map either way.
        $recentInvoices = $client->invoices()->limit(3)->get()->map($mapInvoice);

        $yearParam = (string) request()->query('invoice_year', (string) now()->year);
        $invoiceYear = $yearParam === 'all' ? null : (int) $yearParam;
        $monthParam = request()->query('invoice_month');
        $invoiceMonth = is_numeric($monthParam) && (int) $monthParam >= 1 && (int) $monthParam <= 12 ? (int) $monthParam : null;

        $filteredInvoices = fn () => $client->invoices()
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
        if ($invoiceYear !== null) {
            $periodFrom = Carbon::create($invoiceYear, $invoiceMonth ?? 1, 1)->startOfDay();
            $periodTo = $invoiceMonth !== null ? $periodFrom->copy()->endOfMonth() : $periodFrom->copy()->endOfYear();
        }

        $invoiceFilters = [
            'year' => $invoiceYear ?? 'all',
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
        // Advances (ZAL) repeat the full contract net on every document, so
        // they are excluded from the rolling total; sale_date matches how the
        // books recognise the same invoices.
        $invoicedYear = (int) $client->invoices()
            ->countsAsRevenue()
            ->whereRaw(Invoice::ACCRUAL_DATE_SQL.' >= ?', [now()->subYear()->toDateString()])
            ->sum('net_price');

        // What this month actually offers: the pool plus whatever last month's
        // report closed with, capped at the agreed ceiling. Showing the bare
        // pool understates the budget for any client carrying a surplus.
        $contractedHours = (float) $client->activeRetainersOn(now())->sum('monthly_hours');
        $carriedHours = $this->carriedHoursInto($client, now()->startOfMonth());
        $capHours = $client->activeRetainerOn(now())?->rollover_cap_hours;
        $availableHours = $capHours !== null
            ? min($contractedHours + $carriedHours, (float) $capHours)
            : $contractedHours + $carriedHours;

        $overview = [
            'month_hours' => round($monthMinutes / 60, 1),
            'contracted_hours' => $contractedHours,
            'carried_hours' => round($carriedHours, 2),
            'available_hours' => round($availableHours, 2),
            'latest_report' => $latestReport ? [
                'id' => $latestReport->id,
                'period_type' => $latestReport->period_type,
                'period_start' => $latestReport->period_start?->toDateString(),
                'period_end' => $latestReport->period_end?->toDateString(),
                'status' => $latestReport->status,
            ] : null,
            'invoices' => [
                'recent' => $recentInvoices,
                'net_last_12_months' => (float) ($invoicedYear / 100),
                'currency' => $recentInvoices->first()['currency'] ?? 'PLN',
            ],
        ];

        return Inertia::render('clients/edit', [
            'client' => new ClientResource($client),
            'overview' => $overview,
            'invoices' => $invoices,
            'invoiceFilters' => $invoiceFilters,
            'projects' => $projects,
            'agentTasks' => $agentTasks,
            'agentLanes' => Task::AGENT_LANES,
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
            'trelloEnabled' => $trelloIntegration?->isConfigured() ?? false,
            'clockifyEnabled' => Auth::user()->account->integrations()
                ->where('provider', 'clockify')
                ->where('is_enabled', true)
                ->first()?->isConfigured() ?? false,
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
        $client->update(['is_pinned' => ! $client->is_pinned]);

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

    /**
     * Hours carried into a month, from the closing balance of the last report
     * that ended before it. Negative when the client overran, which is the
     * honest reading: they start the month already in debt.
     */
    private function carriedHoursInto(Client $client, Carbon $monthStart): float
    {
        $previous = $client->reports()
            ->where('period_end', '<', $monthStart)
            ->orderByDesc('period_end')
            ->first();

        return $previous !== null ? $previous->closingBalanceHours() : 0.0;
    }
}
