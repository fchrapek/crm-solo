<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LeadsRequest;
use App\Http\Resources\LeadCollection;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadStageEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The lead-gen funnel surface (config/leadgen.php, vault plan section 05).
 *
 * Pipeline vocabulary is never hardcoded here — stages, sources and lanes all
 * come from the config contract so the plan and the CRM cannot drift.
 */
final class LeadsController extends Controller
{
    public function index()
    {
        // Brand filter (the pipeline column IS the brand attribution). One
        // board for everything — the stage vocabulary is unified across
        // pipelines, so mixed lanes are coherent; 'all' is the default.
        $pipeline = (string) Request::input('pipeline', 'all');
        if (! in_array($pipeline, [...Lead::pipelines(), 'all'], true)) {
            $pipeline = 'all';
        }

        $stage = (string) Request::input('stage', 'all');
        if (! in_array($stage, [...Lead::unifiedRowStages(), 'all'], true)) {
            $stage = 'all';
        }

        $tier = (string) Request::input('tier', 'all');
        if (! in_array($tier, [...Lead::TIERS, 'all'], true)) {
            $tier = 'all';
        }

        $filters = [
            ...Request::only(['search', 'trashed', 'source']),
            'pipeline' => $pipeline,
            'stage' => $stage,
            'tier' => $tier,
        ];

        $query = Auth::user()->account->leads()
            ->with('client:id,name')
            ->orderByDesc('captured_at')
            ->filter($filters);

        // Tier is derived from config points at read time and deliberately not
        // a column, so SQL cannot filter on it. Resolve it in PHP over the
        // already-narrowed set instead of faking it in the query — the funnel
        // is small by design (the plan's own target is ~22 kiwwwi leads per 6
        // months), so this stays cheap. If a funnel ever outgrows that, the
        // fix is a materialised tier column refreshed on config change, not a
        // JSON expression in the WHERE clause.
        $leads = $tier === 'all'
            ? $query->paginate(50)->withQueryString()
            : $this->paginateByTier($query->get(), $tier);

        return Inertia::render('leads/index', [
            'filters' => $filters,
            'pipelines' => $this->pipelineMeta(),
            // The one board vocabulary + merged label maps: lanes are shared
            // across brands, so labels merge too (later pipelines win — they
            // only diverge for non-canonical custom stages anyway).
            'stages' => Lead::unifiedRowStages(),
            'stage_labels' => (object) $this->mergedStageLabels(),
            'sources' => Lead::sources(),
            'source_labels' => $this->sourceLabels(),
            'tiers' => Lead::TIERS,
            'leads' => new LeadCollection($leads),
        ]);
    }

    public function create()
    {
        return Inertia::render('leads/create', [
            'pipelines' => $this->pipelineMeta(),
            'sources' => Lead::sources(),
            'source_labels' => $this->sourceLabels(),
            'pipeline' => Request::input('pipeline', Lead::pipelines()[0] ?? ''),
        ]);
    }

    public function store(LeadsRequest $request): RedirectResponse
    {
        $lead = Auth::user()->account->leads()->create($request->validated());

        return Redirect::route('leads.edit', $lead)->with('success', __('Lead captured'));
    }

    public function edit(Lead $lead)
    {
        $lead->load('client:id,name');

        // Newest first, with id as the tiebreak: capture and a same-second
        // stage move share a created_at, and timestamp alone would render the
        // capture event above the move it preceded.
        $events = $lead->stageEvents()
            ->with('user:id,first_name,last_name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('leads/edit', [
            'lead' => [
                ...$lead->only('id', 'pipeline', 'name', 'company', 'email', 'phone', 'source', 'stage', 'client_id', 'notes', 'deleted_at'),
                'captured_at' => $lead->captured_at?->toIso8601String(),
                'client' => $lead->client?->only('id', 'name'),
                'is_won' => $lead->isWon(),
                // Ticked slugs are the state; score and tier are derived here
                // and sent read-only so the page never re-implements the maths.
                'score_factors' => (object) $lead->scoreFactors(),
                'score_total' => $lead->scoreTotal(),
                'tier' => $lead->tier(),
                'tier_routing' => $lead->tierRouting(),
            ],
            'pipelines' => $this->pipelineMeta(),
            'source_labels' => $this->sourceLabels(),
            // Thresholds travel to the page so the score panel states the
            // Gold/Oak cut-offs without restating config in TypeScript.
            'tier_thresholds' => [
                'gold_min' => (int) config('leadgen.tiers.gold_min'),
                'oak_min' => (int) config('leadgen.tiers.oak_min'),
            ],
            'events' => $events
                ->map(fn ($event) => [
                    ...$event->only('id', 'from_stage', 'to_stage', 'note'),
                    'created_at' => $event->created_at?->toIso8601String(),
                    'user_name' => $event->user ? mb_trim($event->user->first_name.' '.$event->user->last_name) : null,
                ]),
        ]);
    }

    public function update(LeadsRequest $request, Lead $lead): RedirectResponse|JsonResponse
    {
        $validated = $request->validated();

        // Stage moves go through transitionTo() so the append-only history —
        // the funnel's measurement — records every hop. A bare update() would
        // move the lead silently and leave the rate maths blind.
        $stage = $validated['stage'] ?? null;
        unset($validated['stage'], $validated['pipeline'], $validated['source']);

        $lead->update($validated);

        if ($stage !== null && $stage !== $lead->stage) {
            $lead->transitionTo($stage, null, Auth::user());
        }

        // The record view saves per field (on blur / on score tick) via fetch,
        // so it needs the re-derived numbers back without a redirect+flash —
        // a toast per blurred field would be noise, not feedback.
        if ($request->wantsJson()) {
            $lead->refresh();

            return response()->json([
                'score_total' => $lead->scoreTotal(),
                'tier' => $lead->tier(),
                'tier_routing' => $lead->tierRouting(),
                'stage' => $lead->stage,
                'is_won' => $lead->isWon(),
            ]);
        }

        return Redirect::route('leads.edit', $lead)->with('success', __('Lead updated'));
    }

    public function destroy(Lead $lead): RedirectResponse
    {
        $lead->delete();

        return Redirect::route('leads.index')->with('success', __('Lead deleted'));
    }

    public function restore(Lead $lead): RedirectResponse
    {
        $lead->restore();

        return Redirect::back()->with('success', __('Lead restored'));
    }

    /**
     * Kanban drag. Validated against the lead's own pipeline, so a card can
     * never land in the other funnel's lane — or on the analytics-only
     * 'visitor', which rowStages() omits.
     *
     * Returns JSON, not a redirect: the board calls this with fetch(), and
     * fetch follows a 302 with the SAME method (only POST downgrades to GET),
     * so a redirect here lands a PATCH on a GET-only route and comes back 405.
     * The board would then roll the card back even though the move committed.
     */
    public function transitionStage(Lead $lead): JsonResponse
    {
        $validated = Request::validate([
            'stage' => ['required', Rule::in(Lead::rowStages((string) $lead->pipeline))],
            'note' => ['nullable', 'string'],
        ]);

        $lead->transitionTo($validated['stage'], $validated['note'] ?? null, Auth::user());

        return response()->json([
            'stage' => $lead->stage,
            'is_won' => $lead->isWon(),
        ]);
    }

    /**
     * Remove a single stage-history entry — data hygiene for accidental or
     * test moves (a polluted history poisons the Month-2 measured rates more
     * than a curated one). The capture event stays undeletable: it records
     * that the lead exists and when, which is creation truth, not a move.
     */
    public function destroyEvent(LeadStageEvent $event): RedirectResponse
    {
        if ($event->account_id !== Auth::user()->account_id) {
            abort(403);
        }
        if ($event->from_stage === null) {
            abort(422, 'The capture event cannot be deleted — it records when the lead entered the funnel.');
        }

        $event->delete();

        return Redirect::back()->with('success', __('History entry removed'));
    }

    /**
     * The conversion seam: a won lead becomes a Client, keeping `client_id` as
     * the attribution link so "which channel produced this client" survives.
     * Only offered at the funnel's terminal stage.
     */
    public function convertToClient(Lead $lead): RedirectResponse
    {
        if (! $lead->isWon()) {
            return Redirect::back()->with('error', __('Only a won lead can be converted to a client.'));
        }

        if ($lead->client_id !== null) {
            return Redirect::route('clients.edit', $lead->client_id);
        }

        $client = DB::transaction(function () use ($lead): Client {
            $client = Client::create([
                'account_id' => $lead->account_id,
                'name' => $lead->company ?: $lead->name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'type' => 'business',
                'notes' => $lead->notes,
            ]);

            $lead->update(['client_id' => $client->id]);

            return $client;
        });

        return Redirect::route('clients.edit', $client->id)
            ->with('success', __('Lead converted to client'));
    }

    /**
     * Paginate a tier-filtered collection by hand. Only reached when a tier
     * filter is active — see the note in index() on why tier cannot be a
     * WHERE clause.
     *
     * @param  Collection<int, Lead>  $leads
     * @return LengthAwarePaginatorContract<int, Lead>
     */
    private function paginateByTier(Collection $leads, string $tier): LengthAwarePaginatorContract
    {
        $matching = $leads->filter(fn (Lead $lead): bool => $lead->tier() === $tier)->values();
        $perPage = 50;
        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $matching->forPage($page, $perPage)->values(),
            $matching->count(),
            $perPage,
            $page,
            ['path' => Request::url(), 'query' => Request::query()],
        );
    }

    /**
     * Lane/stage vocabulary handed to the frontend. rowStages() — never the
     * raw stage list — so the analytics-only 'visitor' can never render as a
     * kanban column the user could drop a card onto.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pipelineMeta(): array
    {
        return array_map(function (string $key): array {
            $config = Lead::pipelineConfig($key);

            return [
                'key' => $key,
                'label' => $config['label'] ?? null,
                'stages' => Lead::rowStages($key),
                'won_stage' => Lead::wonStage($key),
                // The scoring map travels with the pipeline so the checkbox group
                // renders the plan's own points instead of a copy in the frontend.
                'scoring' => Lead::scoringMap($key),
                // Optional display labels for non-canonical vocabulary. The
                // frontend resolves every slug through config label ->
                // translation -> humanized slug, so nothing renders raw.
                'labels' => [
                    'stages' => (object) ($config['stage_labels'] ?? []),
                    'factors' => (object) ($config['factor_labels'] ?? []),
                    'categories' => (object) ($config['category_labels'] ?? []),
                    'routing' => (object) ($config['routing_labels'] ?? []),
                ],
            ];
        }, Lead::pipelines());
    }

    /**
     * Optional slug => label map for sources (config 'source_labels') — the
     * first link of the frontend's label chain; translations and the
     * humanized-slug fallback cover everything it omits.
     */
    private function sourceLabels(): object
    {
        return (object) config('leadgen.source_labels', []);
    }

    /** @return array<string, string> */
    private function mergedStageLabels(): array
    {
        $merged = [];
        foreach (Lead::pipelines() as $pipeline) {
            $merged = [...$merged, ...(array) (Lead::pipelineConfig($pipeline)['stage_labels'] ?? [])];
        }

        return $merged;
    }
}
