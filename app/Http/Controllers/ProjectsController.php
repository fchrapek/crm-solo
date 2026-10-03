<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Integration;
use App\Models\Project;
use App\Models\Task;
use App\Services\Integrations\Trello\CardFinishReconciler;
use App\Services\Integrations\Trello\TrelloBoardSyncRunning;
use App\Services\Integrations\Trello\TrelloListMapper;
use App\Services\Integrations\Trello\TrelloService;
use App\Services\TaskSources\TaskSourceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

final class ProjectsController extends Controller
{
    public function __construct(
        private readonly TaskSourceRegistry $taskSources,
    ) {}

    public function store(Request $request, Client $client): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
        ]);

        Project::create([
            'account_id' => $client->account_id,
            'client_id' => $client->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        return back()->with('success', __('Project created.'));
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeProject($project);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            // Preview = the command that serves the project locally (ddev,
            // vite, rails, …). Stack-agnostic — we just spawn it via ttyd.
            'preview_command' => 'nullable|string|max:500',
            'preview_working_dir' => 'nullable|string|max:255',
            'preview_url' => 'nullable|string|max:500',
            // Whether this project is a site in the monthly close — it gets its
            // own set of site steps on the client's checklist.
            'include_in_month_close' => 'sometimes|boolean',
            // Folder that directly contains this site's {YYYY}/{YYYYMMDD} dumps.
            'backup_path' => 'nullable|string|max:1024',
        ]);

        $project->update($validated);

        return back()->with('success', __('Project updated.'));
    }

    public function destroy(Project $project): RedirectResponse
    {
        $this->authorizeProject($project);

        if ($project->trello_board_id !== null) {
            abort(422, 'Trello-backed projects must be unlinked first.');
        }

        // Project::deleting removes its tasks one by one, so each task's own hook runs.
        DB::transaction(fn () => $project->delete());

        return back()->with('success', __('Project deleted.'));
    }

    /**
     * Connect a private project to a Trello board — either a freshly-created
     * board (mode=create, default) or an existing one the user already
     * maintains on Trello (mode=link + trello_board_id).
     */
    public function connectTrello(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeProject($project);

        if ($project->trello_board_id !== null) {
            abort(422, 'Project is already connected to Trello.');
        }

        $validated = $request->validate([
            'mode' => 'nullable|string|in:create,link',
            'trello_board_id' => 'nullable|string|max:100',
        ]);

        $mode = $validated['mode'] ?? 'create';

        if ($mode === 'link' && empty($validated['trello_board_id'])) {
            abort(422, 'A Trello board id is required when linking an existing board.');
        }

        $integration = $this->resolveTrelloIntegration();
        $provider = $this->taskSources->get('trello');

        try {
            if ($mode === 'link') {
                $provider->linkExistingContainer($project, $integration, $validated['trello_board_id']);

                return back()->with('success', __('Linked existing Trello board.'));
            }

            $provider->connectNewContainer($project, $integration);
        } catch (Throwable $e) {
            return back()->withErrors(['trello' => $e->getMessage()]);
        }

        return back()->with('success', __('Connected to Trello.'));
    }

    /**
     * Detach a Trello board from a project. The project stays under its client
     * (becomes private). Existing tasks pulled from Trello (`source='trello'`)
     * are preserved as historical records — they stop receiving sync updates.
     */
    public function disconnectTrello(Project $project): RedirectResponse
    {
        $this->authorizeProject($project);

        if ($project->trello_board_id === null) {
            abort(422, 'Project is not connected to Trello.');
        }

        $this->taskSources->get('trello')->disconnect($project);

        return back()->with('success', __('Disconnected from Trello.'));
    }

    /**
     * Same logic as the periodic background sync, just on demand.
     */
    public function syncTrello(Project $project): RedirectResponse
    {
        $this->authorizeProject($project);

        if ($project->trello_board_id === null) {
            abort(422, 'Project is not connected to Trello.');
        }

        $integration = $this->resolveTrelloIntegration();

        try {
            $stats = $this->taskSources->get('trello')->syncProject($project, $integration);
        } catch (TrelloBoardSyncRunning) {
            return back()->with('error', __('This board is already syncing. Try again in a moment.'));
        }

        $message = __('Board synced successfully.')." ({$stats['created']} created, {$stats['updated']} updated)";

        return back()->with('success', $message);
    }

    /**
     * Configure how Trello lists map to CRM lanes. Mapping covers any Trello
     * list to any canonical-or-custom CRM lane; "Done" is whichever lists map
     * to LANE_DONE. Saving the mapping also bulk-updates existing tasks'
     * list_name based on their stored trello_list_id, so the change takes
     * effect immediately without waiting for the next Trello sync.
     *
     * Custom lanes (project.settings.custom_lanes) let users define
     * additional CRM columns beyond the canonical five — useful when a
     * Trello board has client-specific stages (e.g. "Awaiting client").
     */
    public function updateTrelloListMapping(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeProject($project);

        if ($project->trello_board_id === null) {
            abort(422, 'Project is not connected to Trello.');
        }

        $customLanes = collect($request->input('custom_lanes', []))
            ->filter(fn ($lane) => is_string($lane))
            ->map(fn (string $lane) => mb_trim($lane))
            ->filter()
            ->reject(fn (string $lane) => in_array($lane, TrelloListMapper::CANONICAL_LANES, true))
            ->unique()
            ->values()
            ->all();

        $allowedLanes = array_merge(TrelloListMapper::CANONICAL_LANES, $customLanes);

        $validated = $request->validate([
            'mapping' => 'required|array',
            'mapping.*' => ['required', 'string', Rule::in($allowedLanes)],
        ]);

        // A board sync rewrites the same settings and lanes: the two never run at once.
        $lock = TrelloService::boardLock($project->trello_board_id);
        if (! $lock->get()) {
            return back()->with('error', __('This board is already syncing. Try again in a moment.'));
        }

        try {
            $project->refresh();
            $settings = $project->settings ?? [];
            $settings['trello_list_mapping'] = $validated['mapping'];
            $settings['custom_lanes'] = $customLanes;
            $project->update(['settings' => $settings]);

            // Apply the new mapping to existing tasks immediately. Without this,
            // the user has to wait for the next Trello sync (every N minutes) to
            // see cards redistribute across lanes, which surprises everyone.
            // Same completion and finish rules as the sync; each card's lane is
            // read from its list as it is under the row lock.
            $project->tasks()
                ->whereIn('trello_list_id', array_keys($validated['mapping']))
                ->get()
                ->each(fn (Task $card) => CardFinishReconciler::remap($card));
        } finally {
            $lock->release();
        }

        return back()->with('success', __('List mapping saved.'));
    }

    /**
     * List Trello boards on the user's account, annotated with their current
     * link status on this CRM account. Boards already linked to another
     * Trello-backed project are returned but flagged so the picker can
     * disable them with an explanatory label.
     */
    public function availableTrelloBoards(Project $project): JsonResponse
    {
        $this->authorizeProject($project);

        if ($project->trello_board_id !== null) {
            abort(422, 'Project is already connected to Trello.');
        }

        $integration = $this->resolveTrelloIntegration();

        try {
            $boards = collect($this->taskSources->get('trello')->listContainers($integration));
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'fetch_failed',
                'message' => $e->getMessage(),
            ], 502);
        }

        $linkedProjects = Project::with('client:id,name')
            ->where('account_id', $project->account_id)
            ->whereNotNull('trello_board_id')
            ->get()
            ->keyBy('trello_board_id');

        $entries = $boards->map(function (array $board) use ($linkedProjects) {
            $linked = $board['id'] !== null ? $linkedProjects->get($board['id']) : null;

            return [
                ...$board,
                'linked_status' => match (true) {
                    $linked === null => 'available',
                    $linked->client_id === null => 'orphan',
                    default => 'linked',
                },
                'linked_client_name' => $linked?->client?->name,
            ];
        })->values()->all();

        return response()->json(['boards' => $entries]);
    }

    private function resolveTrelloIntegration(): Integration
    {
        $integration = Integration::where('account_id', Auth::user()->account_id)
            ->where('provider', 'trello')
            ->where('is_enabled', true)
            ->first();

        if ($integration === null || ! $integration->isConfigured()) {
            abort(422, 'Trello integration is not configured.');
        }

        return $integration;
    }

    private function authorizeProject(Project $project): void
    {
        if ($project->account_id !== Auth::user()->account_id) {
            abort(403);
        }
    }
}
