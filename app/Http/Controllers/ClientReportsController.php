<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientReport;
use App\Models\ClientReportRevision;
use App\Services\Humanizer;
use App\Services\Reports\BillingSummary;
use App\Services\Reports\ReportComposerRegistry;
use App\Services\Reports\ReportDataAggregator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

final class ClientReportsController extends Controller
{
    public function __construct(
        private readonly ReportDataAggregator $aggregator,
        private readonly ReportComposerRegistry $composers,
    ) {}

    public function store(Request $request, Client $client): RedirectResponse
    {
        // Weekly reports are refused: the retainer pool is monthly, and a week would be credited all of it.
        $data = $request->validate([
            'period_type' => ['required', 'in:month'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'composer_key' => ['nullable', 'string', 'max:64'],
        ], [
            'period_type.in' => __('Reports are monthly: the retainer hours are a monthly pool.'),
        ]);

        $start = Carbon::parse($data['period_start']);
        $end = Carbon::parse($data['period_end']);

        $composer = isset($data['composer_key'])
            ? $this->composers->get($data['composer_key'])
            : $this->composers->default();

        $context = $this->aggregator->aggregate(
            $client,
            $start,
            $end,
            $data['period_type'],
            App::getLocale(),
        );

        $body = $composer->compose($context);

        $report = $client->reports()->create([
            'account_id' => $client->account_id,
            'period_type' => $data['period_type'],
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'contracted_hours' => $context->contractedHours(),
            'actual_hours' => $context->actualHours,
            // Carried in from the previous period, and the ceiling in force when
            // the report was generated — both snapshotted so a later retainer
            // edit never rewrites what this period promised.
            'opening_balance_hours' => $context->openingBalanceHours,
            'rollover_cap_hours' => $context->rolloverCapHours,
            'currency' => $context->currency(),
            'composer_key' => $composer->key(),
            'body_markdown' => $body,
            'status' => ClientReport::STATUS_DRAFT,
            'generated_at' => now(),
        ]);

        return Redirect::route('client-reports.edit', [
            'client' => $client->id,
            'report' => $report->id,
        ])->with('success', __('Draft report generated.'));
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
                'composer_key_before' => $r->composer_key_before,
                'body_markdown_before' => $r->body_markdown_before,
                'opening_balance_hours_before' => $r->opening_balance_hours_before !== null ? (float) $r->opening_balance_hours_before : null,
                'rollover_cap_hours_before' => $r->rollover_cap_hours_before !== null ? (float) $r->rollover_cap_hours_before : null,
                'created_at' => $r->created_at->toIso8601String(),
                'user' => $r->user ? [
                    'id' => $r->user->id,
                    'name' => mb_trim($r->user->first_name.' '.$r->user->last_name),
                ] : null,
            ]);

        return Inertia::render('clients/reports/edit', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
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

        return DB::transaction(function () use ($report, $data): RedirectResponse {
            $report = $this->lockFresh($report);
            if ($report->version() !== $data['version']) {
                return $this->staleEdit();
            }

            // No-op when the body is unchanged — avoid spurious revision rows.
            if (Humanizer::clean($data['body_markdown']) === $report->body_markdown) {
                return Redirect::back();
            }

            $report->recordRevision(ClientReportRevision::REASON_UPDATE, Auth::user());
            $report->update(['body_markdown' => $data['body_markdown']]);

            return Redirect::back()->with('success', __('Report saved.'));
        });
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

        return DB::transaction(function () use ($report, $data): RedirectResponse {
            $report = $this->lockFresh($report);
            if (isset($data['version']) && $report->version() !== $data['version']) {
                return $this->staleEdit();
            }

            $body = (string) ($data['body_markdown'] ?? $report->body_markdown);
            $opening = isset($data['opening_balance_hours']) ? (float) $data['opening_balance_hours'] : null;

            // A report with no retainer prints no balance, so there is nothing to re-render.
            $summary = $report->contracted_hours === null
                ? ['status' => BillingSummary::REWRITTEN, 'body' => $body]
                : BillingSummary::replaceIn(
                    $body,
                    (float) $opening,
                    (float) $report->contracted_hours,
                    (float) $report->actual_hours,
                    $report->rollover_cap_hours !== null ? (float) $report->rollover_cap_hours : null,
                    (string) ($report->period_type ?? 'month'),
                );
            $newBody = Humanizer::clean($summary['body']);

            $current = $report->opening_balance_hours !== null ? (float) $report->opening_balance_hours : null;
            if ($opening === $current && $newBody === $report->body_markdown) {
                return Redirect::back();
            }

            $report->recordRevision(ClientReportRevision::REASON_OPENING_BALANCE, Auth::user());
            $report->update([
                'opening_balance_hours' => $opening,
                'body_markdown' => $newBody,
            ]);

            return Redirect::back()->with('success', match ($summary['status']) {
                BillingSummary::MISSING => __('Opening balance saved. The report has no billing summary, so its text was left as is.'),
                BillingSummary::AMBIGUOUS => __('Opening balance saved. The report has more than one billing summary, so its text was left as is: check the balances in it by hand.'),
                default => __('Opening balance saved.'),
            });
        });
    }

    public function regenerate(Request $request, Client $client, ClientReport $report): RedirectResponse
    {
        $this->authorizeReport($client, $report);

        $data = $request->validate([
            'composer_key' => ['nullable', 'string', 'max:64'],
            'recalculate_opening_balance' => ['sometimes', 'boolean'],
        ]);

        // The saved opening balance is the owner's figure and survives a regenerate;
        // carrying it in again from the previous report is an explicit choice.
        $opening = $report->opening_balance_hours !== null && ! $request->boolean('recalculate_opening_balance')
            ? (float) $report->opening_balance_hours
            : null;

        // A hand-written report carries a key no composer backs ('manual'), so
        // fall back to the default rather than 500 on regenerate.
        $composer = isset($data['composer_key'])
            ? $this->composers->get($data['composer_key'])
            : $this->composers->getOrDefault($report->composer_key);

        // Composing can take seconds (an AI call), so the write is refused if the
        // report changed in the meantime rather than reverting that change.
        $version = $report->version();

        $context = $this->aggregator->aggregate(
            $client,
            $report->period_start,
            $report->period_end,
            $report->period_type,
            App::getLocale(),
            $opening,
        );

        $body = $composer->compose($context);

        return DB::transaction(function () use ($report, $composer, $body, $context, $version): RedirectResponse {
            $report = $this->lockFresh($report);
            if ($report->version() !== $version) {
                return Redirect::back()->with('error', __('The report changed while it was being regenerated, so nothing was replaced. Reload the page and regenerate again.'));
            }

            $report->recordRevision(ClientReportRevision::REASON_REGENERATE, Auth::user());
            $report->update([
                'composer_key' => $composer->key(),
                'body_markdown' => $body,
                'contracted_hours' => $context->contractedHours(),
                'actual_hours' => $context->actualHours,
                'opening_balance_hours' => $context->openingBalanceHours,
                'rollover_cap_hours' => $context->rolloverCapHours,
                'currency' => $context->currency(),
                'generated_at' => now(),
            ]);

            return Redirect::back()->with('success', __('Report regenerated.'));
        });
    }

    public function finalize(Client $client, ClientReport $report): RedirectResponse
    {
        $this->authorizeReport($client, $report);

        DB::transaction(function () use ($report): void {
            $report = $this->lockFresh($report);
            if ($report->status === ClientReport::STATUS_FINALIZED) {
                return;
            }

            $report->recordRevision(ClientReportRevision::REASON_FINALIZE, Auth::user());
            $report->update([
                'status' => ClientReport::STATUS_FINALIZED,
                'finalized_at' => now(),
            ]);
        });

        return Redirect::back()->with('success', __('Report finalized.'));
    }

    public function reopen(Client $client, ClientReport $report): RedirectResponse
    {
        $this->authorizeReport($client, $report);

        DB::transaction(function () use ($report): void {
            $report = $this->lockFresh($report);
            if ($report->status === ClientReport::STATUS_DRAFT) {
                return;
            }

            $report->recordRevision(ClientReportRevision::REASON_REOPEN, Auth::user());
            $report->update([
                'status' => ClientReport::STATUS_DRAFT,
                'finalized_at' => null,
                'sent_at' => null,
            ]);
        });

        return Redirect::back()->with('success', __('Report reopened as draft.'));
    }

    public function destroy(Client $client, ClientReport $report): RedirectResponse
    {
        $this->authorizeReport($client, $report);

        DB::transaction(fn () => $report->delete());

        return Redirect::route('clients.edit', $client)
            ->with('success', __('Report deleted.'));
    }

    /** The report row, locked and re-read, so a revision snapshots what is stored now. */
    private function lockFresh(ClientReport $report): ClientReport
    {
        return ClientReport::query()->lockForUpdate()->findOrFail($report->id);
    }

    private function staleEdit(): RedirectResponse
    {
        return Redirect::back()->with('error', __('The report changed since you opened it, so your edit was not saved. Copy your text, reload the page and apply it again.'));
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
        ];
    }
}
