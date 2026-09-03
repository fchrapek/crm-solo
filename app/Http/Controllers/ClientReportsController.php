<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientReport;
use App\Models\ClientReportRevision;
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
        $data = $request->validate([
            'period_type' => ['required', 'in:week,month'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'composer_key' => ['nullable', 'string', 'max:64'],
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
        ]);

        // No-op when the body is unchanged — avoid spurious revision rows.
        if ($data['body_markdown'] === $report->body_markdown) {
            return Redirect::back();
        }

        DB::transaction(function () use ($report, $data): void {
            $report->recordRevision(ClientReportRevision::REASON_UPDATE, Auth::user());
            $report->update(['body_markdown' => $data['body_markdown']]);
        });

        return Redirect::back()->with('success', __('Report saved.'));
    }

    /**
     * Set the hours-bank opening balance carried into this period. Structured
     * field, not body content — no revision snapshot needed.
     */
    public function updateOpeningBalance(Request $request, Client $client, ClientReport $report): RedirectResponse
    {
        $this->authorizeReport($client, $report);

        $data = $request->validate([
            'opening_balance_hours' => ['nullable', 'numeric', 'between:-9999,9999'],
        ]);

        $report->update(['opening_balance_hours' => $data['opening_balance_hours']]);

        return Redirect::back()->with('success', __('Saved.'));
    }

    public function regenerate(Request $request, Client $client, ClientReport $report): RedirectResponse
    {
        $this->authorizeReport($client, $report);

        $data = $request->validate([
            'composer_key' => ['nullable', 'string', 'max:64'],
        ]);

        // A hand-written report carries a key no composer backs ('manual'), so
        // fall back to the default rather than 500 on regenerate.
        $composer = isset($data['composer_key'])
            ? $this->composers->get($data['composer_key'])
            : $this->composers->getOrDefault($report->composer_key);

        $context = $this->aggregator->aggregate(
            $client,
            $report->period_start,
            $report->period_end,
            $report->period_type,
            App::getLocale(),
        );

        $body = $composer->compose($context);

        DB::transaction(function () use ($report, $composer, $body, $context): void {
            $report->recordRevision(ClientReportRevision::REASON_REGENERATE, Auth::user());
            $report->update([
                'composer_key' => $composer->key(),
                'body_markdown' => $body,
                'contracted_hours' => $context->contractedHours(),
                'actual_hours' => $context->actualHours,
                'currency' => $context->currency(),
                'generated_at' => now(),
            ]);
        });

        return Redirect::back()->with('success', __('Report regenerated.'));
    }

    public function finalize(Client $client, ClientReport $report): RedirectResponse
    {
        $this->authorizeReport($client, $report);

        if ($report->status === ClientReport::STATUS_FINALIZED) {
            return Redirect::back();
        }

        DB::transaction(function () use ($report): void {
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

        if ($report->status === ClientReport::STATUS_DRAFT) {
            return Redirect::back();
        }

        DB::transaction(function () use ($report): void {
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
            'status' => $report->status,
            'generated_at' => $report->generated_at?->toIso8601String(),
            'finalized_at' => $report->finalized_at?->toIso8601String(),
        ];
    }
}
