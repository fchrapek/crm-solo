<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\MonthCloseRun;
use App\Models\MonthCloseStep;
use App\Services\Agent\MonthCloseTicker;
use App\Services\Clients\MonthCloseWorklist;
use App\Support\LegalName;
use App\Support\LocalCalendar;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class MonthCloseController extends Controller
{
    /** The first month the close ran; no earlier month is offered. */
    private const string FIRST_PERIOD = '2026-06';

    /**
     * Figma: Zamknięcie miesiąca, direction A. Every client in the period's close, one row each; the CRM tracks
     * the checklist, the work itself happens with the agent.
     */
    public function index(Request $request, MonthCloseWorklist $worklist): Response
    {
        $period = $this->resolvePeriod((string) $request->input('period', ''));

        return Inertia::render('month-close/index', [
            'period' => $period,
            'months' => $this->trail($period),
            'clients' => array_map($this->listRow(...), $worklist->rows(Auth::user()->account, $period)),
        ]);
    }

    /** Figma: Zamknięcie miesiąca, the client card: the steps per site, then the client's own, beside the hours close. */
    public function show(Request $request, Client $client, MonthCloseWorklist $worklist): Response
    {
        $period = $this->resolvePeriod((string) $request->input('period', ''));
        $row = collect($worklist->rows(Auth::user()->account, $period))->first(fn (array $row): bool => $row['client']->is($client));
        abort_if($row === null, 404);

        $steps = $this->steps($row['client'], $row['run']);

        return Inertia::render('month-close/show', [
            'period' => $period,
            'months' => $this->trail($period),
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'short_name' => LegalName::short($client->name),
                'type' => $client->month_close_type ?? $row['run']?->close_type,
                'report_mode' => $client->report_mode,
                'ssh' => $client->ssh_config,
            ],
            'sites' => collect($steps)
                ->whereNotNull('project_id')
                ->groupBy('project_id')
                ->map(fn ($site): array => ['project_id' => $site->first()['project_id'], 'name' => $site->first()['project'], 'steps' => $site->values()])
                ->values(),
            'client_steps' => collect($steps)->whereNull('project_id')->values(),
            'settlement' => $row['settlement'],
        ]);
    }

    /** Move one step; the first move of a period starts its close, the same way an agent's tick does. */
    public function tick(Request $request, Client $client, MonthCloseTicker $ticker): RedirectResponse
    {
        $data = $request->validate([
            'period' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! LocalCalendar::isMonth($value)) {
                    $fail(__('The period must be a month as YYYY-MM.'));
                }
            }],
            'step' => ['required', 'string', 'max:64'],
            'project_id' => ['nullable', 'integer'],
            'state' => ['required', Rule::in([MonthCloseStep::STATE_PENDING, MonthCloseStep::STATE_DONE, MonthCloseStep::STATE_SKIPPED])],
        ]);

        try {
            $ticker->tick($client, $data['period'], $data['step'], $data['state'], Auth::user(), isset($data['project_id']) ? (string) $data['project_id'] : null, null, MonthCloseStep::VIA_WEB);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['step' => $e->getMessage()]);
        }

        return Redirect::back();
    }

    private function resolvePeriod(string $input): string
    {
        if (LocalCalendar::isMonth($input)) {
            return $input;
        }

        // You close a month after it is over.
        return LocalCalendar::previousMonth();
    }

    /**
     * The month before, the month and the one after, never past the month in progress nor before the first close.
     *
     * @return list<string>
     */
    private function trail(string $period): array
    {
        $middle = min(max($period, LocalCalendar::nextMonth(self::FIRST_PERIOD)), LocalCalendar::previousMonth());

        return [LocalCalendar::monthBefore($middle), $middle, LocalCalendar::nextMonth($middle)];
    }

    /**
     * @param  array{client: Client, run: ?MonthCloseRun, settlement: array<string, mixed>|null, template_total: int, can_start_checklist: bool}  $row
     * @return array<string, mixed>
     */
    private function listRow(array $row): array
    {
        $steps = collect($this->steps($row['client'], $row['run']));
        $next = $steps->firstWhere('state', MonthCloseStep::STATE_PENDING);
        $settlement = $row['settlement'];
        $finished = $next === null && ($settlement === null || $settlement['state'] === 'closed');

        return [
            'client' => ['id' => $row['client']->id, 'name' => $row['client']->name],
            'total' => $steps->count(),
            'resolved' => $steps->where('state', '!=', MonthCloseStep::STATE_PENDING)->count(),
            'next' => $next === null ? null : ['key' => $next['key'], 'owner' => $next['owner']],
            'sites' => $steps->pluck('project_id')->filter()->unique()->count(),
            'invoice' => $steps->firstWhere('key', 'draft_invoice')['state'] ?? null,
            'settlement' => $settlement,
            'finished_on' => $finished ? collect([
                $row['run']?->completed_at?->copy()->setTimezone(LocalCalendar::timezone())->toDateString(),
                $settlement['closed_on'] ?? null,
            ])->filter()->max() : null,
        ];
    }

    /**
     * The period's steps in checklist order: the run's once it started, else what starting it would seed.
     *
     * @return list<array{key: string, project_id: int|null, project: string|null, state: string, owner: string, note: string|null, completed_on: string|null, via: string|null}>
     */
    private function steps(Client $client, ?MonthCloseRun $run): array
    {
        $owner = fn (string $key): string => in_array($key, MonthCloseRun::OWNER_STEPS, true) ? 'you' : 'agent';

        if ($run !== null) {
            return $run->steps->sortBy('position')->map(fn (MonthCloseStep $step): array => [
                'key' => $step->step_key,
                'project_id' => $step->project_id === null ? null : (int) $step->project_id,
                'project' => $step->project?->name,
                'state' => $step->state,
                'owner' => $owner($step->step_key),
                'note' => $step->note,
                'completed_on' => $step->completed_at?->copy()->setTimezone(LocalCalendar::timezone())->toDateString(),
                'via' => $step->completed_via,
            ])->values()->all();
        }

        if ($client->month_close_type === null || ! $client->include_in_month_close) {
            return [];
        }

        return array_map(fn (array $step): array => [
            'key' => $step['key'],
            'project_id' => $step['project_id'],
            'project' => $step['project'],
            'state' => MonthCloseStep::STATE_PENDING,
            'owner' => $owner($step['key']),
            'note' => null,
            'completed_on' => null,
            'via' => null,
        ], MonthCloseRun::stepTemplateFor($client));
    }
}
