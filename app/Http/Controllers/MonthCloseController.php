<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\MonthCloseRun;
use App\Models\MonthCloseStep;
use App\Support\LocalCalendar;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class MonthCloseController extends Controller
{
    /**
     * The monthly close worklist: every maintained client with its checklist
     * for the selected period. The CRM only tracks checklist state — the work
     * itself is done live with the agent.
     */
    public function index(Request $request): Response
    {
        $period = $this->resolvePeriod((string) $request->input('period', ''));

        $clients = Auth::user()->account->clients()
            ->whereNotNull('month_close_type')
            ->where('include_in_month_close', true)
            ->orderBy('name')
            ->with([
                'monthCloseSites:id,client_id,name',
                'monthCloseRuns' => fn ($query) => $query->where('period', $period)->with('steps.project:id,name'),
            ])
            ->get();

        $rows = $clients->map(function (Client $client): array {
            $run = $client->monthCloseRuns->first();

            return [
                'client' => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'type' => $client->month_close_type,
                    'site_count' => $client->monthCloseSites->count(),
                ],
                // What starting the close would seed, so the button can show a
                // count before any row exists.
                'template_total' => count(MonthCloseRun::stepTemplateFor($client)),
                'run' => $run ? $this->serializeRun($run) : null,
            ];
        })->values();

        return Inertia::render('month-close/index', [
            'period' => $period,
            'periods' => $this->recentPeriods(),
            'clients' => $rows,
        ]);
    }

    /**
     * Start (or resume) a client's close for a period — creates the run and
     * seeds its checklist from the template on first call. Idempotent.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'period' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! LocalCalendar::isMonth($value)) {
                    $fail(__('The period must be a month as YYYY-MM.'));
                }
            }],
        ]);

        $client = Auth::user()->account->clients()
            ->whereNotNull('month_close_type')
            ->where('include_in_month_close', true)
            ->findOrFail($data['client_id']);

        DB::transaction(fn () => MonthCloseRun::startFor($client, $data['period']));

        return Redirect::back()->with('success', __('Month close started.'));
    }

    /**
     * Toggle a single checklist step (pending / done / skipped) and recompute
     * the run's overall status.
     */
    public function updateStep(Request $request, MonthCloseStep $step): RedirectResponse
    {
        $data = $request->validate([
            'state' => ['required', Rule::in([
                MonthCloseStep::STATE_PENDING,
                MonthCloseStep::STATE_DONE,
                MonthCloseStep::STATE_SKIPPED,
            ])],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($step, $data): void {
            $isPending = $data['state'] === MonthCloseStep::STATE_PENDING;

            $attributes = [
                'state' => $data['state'],
                'completed_at' => $isPending ? null : now(),
                'completed_by' => $isPending ? null : Auth::id(),
            ];

            if (array_key_exists('note', $data)) {
                $attributes['note'] = $data['note'];
            }

            $step->update($attributes);
            $step->run->refreshStatusFromSteps();
        });

        return Redirect::back()->with('success', __('Checklist updated.'));
    }

    private function resolvePeriod(string $input): string
    {
        if (LocalCalendar::isMonth($input)) {
            return $input;
        }

        // Default to the month just ended — you close a month after it's over.
        return LocalCalendar::previousMonth();
    }

    /**
     * Selectable periods, newest first, floored at the first close month
     * (June 2026) — the month-close bundle started then, so earlier months
     * are never offered.
     *
     * @return list<string>
     */
    private function recentPeriods(): array
    {
        $floor = Carbon::create(2026, 6, 1);
        $cursor = Carbon::instance(LocalCalendar::monthFrom(LocalCalendar::currentMonth(), (string) config('app.timezone')));
        $periods = [];

        while ($cursor->greaterThanOrEqualTo($floor)) {
            $periods[] = $cursor->format('Y-m');
            $cursor->subMonthNoOverflow();
        }

        return $periods;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRun(MonthCloseRun $run): array
    {
        $serialize = fn (MonthCloseStep $step): array => [
            'id' => $step->id,
            'key' => $step->step_key,
            'state' => $step->state,
            'note' => $step->note,
            'completed_at' => $step->completed_at?->toIso8601String(),
        ];

        // Site steps group under their site, in seeded order; steps with no
        // project (reconcile, report, invoice, and every June run's historical
        // keys) fall through to the client-level tail.
        $sites = $run->steps
            ->filter(fn (MonthCloseStep $step): bool => $step->project_id !== null)
            ->groupBy('project_id')
            ->map(fn ($steps): array => [
                'project_id' => (int) $steps->first()->project_id,
                'name' => (string) ($steps->first()->project?->name ?? ''),
                'steps' => $steps->map($serialize)->values(),
            ])
            ->values();

        return [
            'id' => $run->id,
            'period' => $run->period,
            'close_type' => $run->close_type,
            'status' => $run->status,
            'completed_at' => $run->completed_at?->toIso8601String(),
            'total' => $run->steps->count(),
            'resolved' => $run->steps->where('state', '!=', MonthCloseStep::STATE_PENDING)->count(),
            'sites' => $sites,
            'client_steps' => $run->steps
                ->filter(fn (MonthCloseStep $step): bool => $step->project_id === null)
                ->map($serialize)
                ->values(),
        ];
    }
}
