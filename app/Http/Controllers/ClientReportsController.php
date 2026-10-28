<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientReport;
use App\Models\ClientReportRevision;
use App\Services\Reports\ReportComposerRegistry;
use App\Services\Reports\ReportStatusTransition;
use App\Services\Reports\ReportTransitionResult;
use App\Services\Reports\ReportWriter;
use App\Services\Reports\ReportWriteResult;
use App\Support\LegalName;
use App\Support\LocalCalendar;
use Closure;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

final class ClientReportsController extends Controller
{
    public function __construct(
        private readonly ReportComposerRegistry $composers,
        private readonly ReportStatusTransition $transitions,
        private readonly ReportWriter $writer,
    ) {}

    public function store(Request $request, Client $client): RedirectResponse
    {
        // Weekly and partial reports are refused: the retainer pool is monthly, and a shorter period would be credited all of it.
        $data = $request->validate([
            'period_type' => ['required', 'in:month'],
            'period_start' => ['bail', 'required', 'string', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail): void {
                if (self::isDate($value) && Carbon::parse($value)->day !== 1) {
                    $fail(__('A report starts on the first day of a month.'));
                }
            }],
            'period_end' => ['bail', 'required', 'string', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail) use ($request): void {
                $start = $request->input('period_start');
                if (! self::isDate($start) || ! self::isDate($value)) {
                    return;
                }
                if ($value !== Carbon::parse($start)->endOfMonth()->toDateString()) {
                    $fail(__('A report ends on the last day of the month it starts in.'));
                }
            }],
            'composer_key' => ['nullable', 'string', 'max:64'],
        ], [
            'period_type.in' => __('Reports are monthly: the retainer hours are a monthly pool.'),
        ]);

        $result = $this->writer->generate(
            $client,
            Carbon::parse($data['period_start']),
            Carbon::parse($data['period_end']),
            App::getLocale(),
            $data['composer_key'] ?? null,
        );

        return match ($result->outcome) {
            ReportWriteResult::EXISTS => $this->reportExists($client, $result->report),
            ReportWriteResult::REFUSED => Redirect::back()->with('error', $result->message),
            default => Redirect::route('client-reports.edit', ['client' => $client->id, 'report' => $result->report->id])
                ->with('success', $result->message),
        };
    }

    public function edit(Client $client, ClientReport $report): Response
    {
        $this->authorizeReport($client, $report);

        $revisions = $report->revisions()
            ->with('user:id,first_name,last_name')
            ->limit(50)
            ->get()
            ->map(fn (ClientReportRevision $r) => [
                'id' => $r->id,
                'reason' => $r->reason,
                'status_before' => $r->status_before,
                'finalized_at_before' => $r->finalized_at_before?->toIso8601String(),
                'sent_at_before' => $r->sent_at_before?->toIso8601String(),
                'composer_key_before' => $r->composer_key_before,
                'body_markdown_before' => $r->body_markdown_before,
                'opening_balance_hours_before' => $r->opening_balance_hours_before !== null ? (float) $r->opening_balance_hours_before : null,
                'rollover_cap_hours_before' => $r->rollover_cap_hours_before !== null ? (float) $r->rollover_cap_hours_before : null,
                'created_at' => $r->created_at->toIso8601String(),
                'created_on' => $r->created_at->copy()->setTimezone(LocalCalendar::timezone())->toDateString(),
                'user' => $r->user ? [
                    'id' => $r->user->id,
                    'name' => mb_trim($r->user->first_name.' '.$r->user->last_name),
                ] : null,
            ]);

        return Inertia::render('clients/reports/edit', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'short_name' => LegalName::short($client->name),
            ],
            'report' => $this->serialize($report),
            'composers' => collect($this->composers->all())->map(fn ($composer) => [
                'key' => $composer->key(),
                'label' => $composer->label(),
            ])->values(),
            'revisions' => $revisions,
        ]);
    }

    public function update(Request $request, Client $client, ClientReport $report): RedirectResponse
    {
        $this->authorizeReport($client, $report);

        $data = $request->validate([
            'body_markdown' => ['required', 'string', 'max:200000'],
            'version' => ['required', 'string'],
        ]);

        return $this->written($this->writer->updateBody($report, $data['body_markdown'], $data['version'], Auth::user()));
    }

    /**
     * Set the hours-bank opening balance carried into this period. The body's
     * billing summary is re-rendered from the new figure in the same write, so
     * the printed balance and the stored one never disagree; the editor's
     * unsaved body, when sent, is the text that summary is rewritten in.
     */
    public function updateOpeningBalance(Request $request, Client $client, ClientReport $report): RedirectResponse
    {
        $this->authorizeReport($client, $report);

        $data = $request->validate([
            // Present but nullable: an explicit null clears the balance, a missing field is a mistake.
            'opening_balance_hours' => ['present', 'nullable', 'numeric', 'between:-9999,9999'],
            'body_markdown' => ['nullable', 'string', 'max:200000'],
            'version' => ['required_with:body_markdown', 'nullable', 'string'],
        ]);

        return $this->written($this->writer->updateOpening(
            $report,
            isset($data['opening_balance_hours']) ? (float) $data['opening_balance_hours'] : null,
            isset($data['body_markdown']) ? (string) $data['body_markdown'] : null,
            $data['version'] ?? null,
            Auth::user(),
        ));
    }

    public function regenerate(Request $request, Client $client, ClientReport $report): RedirectResponse
    {
        $this->authorizeReport($client, $report);

        $data = $request->validate([
            'composer_key' => ['nullable', 'string', 'max:64'],
            'recalculate_opening_balance' => ['sometimes', 'boolean'],
        ]);

        return $this->written($this->writer->regenerate(
            $report,
            App::getLocale(),
            $data['composer_key'] ?? null,
            $request->boolean('recalculate_opening_balance'),
            Auth::user(),
        ));
    }

    public function finalize(Client $client, ClientReport $report): RedirectResponse
    {
        $this->authorizeReport($client, $report);

        return $this->transitioned($this->transitions->finalize($report, Auth::user()));
    }

    public function markSent(Client $client, ClientReport $report): RedirectResponse
    {
        $this->authorizeReport($client, $report);

        return $this->transitioned($this->transitions->markSent($report, Auth::user()));
    }

    public function reopen(Client $client, ClientReport $report): RedirectResponse
    {
        $this->authorizeReport($client, $report);

        return $this->transitioned($this->transitions->reopen($report, Auth::user()));
    }

    public function destroy(Client $client, ClientReport $report): RedirectResponse
    {
        $this->authorizeReport($client, $report);

        $result = $this->writer->destroy($report);
        if ($result->isRefused()) {
            return Redirect::back()->with('error', $result->message);
        }

        return Redirect::route('clients.show', [$client, 'rozliczenia'])->with('success', $result->message);
    }

    private static function isDate(mixed $value): bool
    {
        // The parser throws on a null byte instead of failing, so such input is refused before it.
        if (! is_string($value) || str_contains($value, "\0")) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /** The month already has a report: open it, where Regenerate refreshes its figures, instead of writing a second one. */
    private function reportExists(Client $client, ClientReport $existing): RedirectResponse
    {
        return Redirect::route('client-reports.edit', ['client' => $client->id, 'report' => $existing->id])
            ->with('error', __('This month already has a report, so no second one was generated. Regenerate this one if its figures are out of date.'));
    }

    private function transitioned(ReportTransitionResult $result): RedirectResponse
    {
        return Redirect::back()->with($result->refused() ? 'error' : 'success', $result->message);
    }

    private function written(ReportWriteResult $result): RedirectResponse
    {
        if ($result->message === '') {
            return Redirect::back();
        }

        return Redirect::back()->with($result->isRefused() ? 'error' : 'success', $result->message);
    }

    private function authorizeReport(Client $client, ClientReport $report): void
    {
        abort_unless(
            $report->client_id === $client->id
                && $report->account_id === Auth::user()->account_id,
            404,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ClientReport $report): array
    {
        return [
            'id' => $report->id,
            'period_type' => $report->period_type,
            'period_start' => $report->period_start->toDateString(),
            'period_end' => $report->period_end->toDateString(),
            'contracted_hours' => $report->contracted_hours !== null ? (float) $report->contracted_hours : null,
            'actual_hours' => (float) $report->actual_hours,
            'opening_balance_hours' => $report->opening_balance_hours !== null ? (float) $report->opening_balance_hours : null,
            'rollover_cap_hours' => $report->rollover_cap_hours !== null ? (float) $report->rollover_cap_hours : null,
            'available_hours' => $report->availableHours(),
            'forfeited_hours' => $report->forfeitedHours(),
            'currency' => $report->currency,
            'composer_key' => $report->composer_key,
            'body_markdown' => $report->body_markdown,
            'version' => $report->version(),
            'status' => $report->status,
            'generated_at' => $report->generated_at?->toIso8601String(),
            'finalized_at' => $report->finalized_at?->toIso8601String(),
            'sent_at' => $report->sent_at?->toIso8601String(),
            'sent_on' => $report->sentOn(),
            'finalized_on' => $report->finalized_at?->copy()->setTimezone(LocalCalendar::timezone())->toDateString(),
            'generated_on' => $report->generated_at?->copy()->setTimezone(LocalCalendar::timezone())->toDateString(),
        ];
    }
}
