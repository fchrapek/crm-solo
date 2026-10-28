<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AgentToken;
use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\Agent\AgentName;
use App\Services\Clients\ClientTasksQuery;
use App\Support\LegalName;
use App\Support\LocalCalendar;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The project page (Figma 770:56830): the Zadania lanes narrowed to one project, and a Projekt column with its
 * Trello state, this month's hours and the project's actions, reached from the projects on Dziennik.
 */
final class ProjectBoardController extends Controller
{
    public function show(Client $client, Project $project): Response
    {
        if ($client->account_id !== Auth::user()->account_id || $project->client_id !== $client->id) {
            abort(404);
        }

        $settings = $project->settings ?? [];
        $month = LocalCalendar::currentMonth();
        [$from, $until] = LocalCalendar::monthRange($month);
        $trello = Auth::user()->account->integrations()->where('provider', 'trello')->where('is_enabled', true)->first();
        $expanded = Request::query('wszystkie');
        $agentTimer = TimeEntry::query()
            ->where('account_id', $client->account_id)
            ->where('project_id', $project->id)
            ->whereNull('end_time')
            ->whereNotNull('actor_token_id')
            ->oldest('start_time')
            ->first(['id', 'start_time', 'actor_token_id']);

        return Inertia::render('project-board/show', [
            'client' => ['id' => $client->id, 'name' => $client->name, 'short_name' => LegalName::short($client->name)],
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'is_private' => $project->trello_board_id === null,
                'trello_url' => $project->trello_url,
                'trello_lists' => $settings['trello_lists'] ?? [],
                'trello_list_mapping' => $settings['trello_list_mapping'] ?? [],
                'custom_lanes' => $settings['custom_lanes'] ?? [],
                'include_in_month_close' => $project->include_in_month_close,
                'backup_path' => $project->backup_path,
                'synced_at' => $project->trello_board_id !== null ? $trello?->last_synced_at?->toIso8601String() : null,
                'month' => $month,
                'month_minutes' => (int) TimeEntry::query()
                    ->where('account_id', $client->account_id)
                    ->where('project_id', $project->id)
                    ->where('start_time', '>=', $from)
                    ->where('start_time', '<', $until)
                    ->sum('duration_minutes'),
                'live_agent' => $agentTimer?->start_time !== null ? [
                    'agent' => AgentName::fromToken(AgentToken::query()->whereKey($agentTimer->actor_token_id)->value('name')),
                    'started_at' => $agentTimer->start_time->toIso8601String(),
                ] : null,
            ],
            ...app(ClientTasksQuery::class)->forProject($client, $project, is_string($expanded) ? $expanded : null),
            'trelloEnabled' => ! config('app.demo') && ($trello?->isConfigured() ?? false),
            'trelloActions' => ! config('app.demo'),
        ]);
    }
}
