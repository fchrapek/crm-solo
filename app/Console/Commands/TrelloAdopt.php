<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Console\Commands\Concerns\ResolvesProjectAndClient;
use App\Models\Integration;
use App\Models\Project;
use App\Services\TaskSources\TaskSourceRegistry;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * The deliberate half of opt-in sync.
 *
 * `trello:sync` only touches boards the CRM has adopted, so there has to be a
 * way to adopt one. Without arguments this lists every board the token can
 * reach and says which are already in — that listing is also the honest answer
 * to "what is Trello actually exposing to this integration".
 */
#[AccountScope(AccountScope::ACTING)]
final class TrelloAdopt extends Command
{
    use AgentConsoleOutput;
    use ResolvesProjectAndClient;

    protected $signature = 'trello:adopt
                            {board? : Trello board ID to adopt}
                            {--client= : Client ID or name to file it under}
                            {--name= : Project name (defaults to the board name)}
                            {--account= : Account ID; must be the acting account}';

    protected $description = 'List Trello boards and adopt one as a project. Only adopted boards are synced.';

    public function handle(TaskSourceRegistry $taskSources): int
    {
        $accountId = $this->actingAccountId();
        if ($accountId === null) {
            return self::FAILURE;
        }

        $integration = Integration::where('provider', 'trello')
            ->where('is_enabled', true)
            ->where('account_id', $accountId)
            ->get()
            ->first(fn (Integration $i): bool => $i->hasValidApiKey());

        if (! $integration) {
            $this->error('No enabled Trello integration with a valid API key.');

            return self::FAILURE;
        }

        $boards = collect($taskSources->get('trello')->listContainers($integration));
        $known = Project::where('account_id', $integration->account_id)
            ->whereNotNull('trello_board_id')
            ->get()
            ->keyBy('trello_board_id');

        $boardId = $this->argument('board');

        if ($boardId === null) {
            $this->listBoards($boards, $known);

            return self::SUCCESS;
        }

        $board = $boards->firstWhere('id', $boardId);
        if (! $board) {
            $this->error("Board \"{$boardId}\" is not visible to this integration. Run without arguments to list.");

            return self::FAILURE;
        }

        if ($existing = $known->get($boardId)) {
            if ($existing->isArchived()) {
                $this->warn("Board is archived as project #{$existing->id}. Restore it instead:");
                $this->line("  php artisan projects:archive {$existing->id} --restore");

                return self::FAILURE;
            }

            $this->warn("Already adopted as project #{$existing->id} \"{$existing->name}\".");

            return self::SUCCESS;
        }

        try {
            $clientId = $this->option('client') === null
                ? null
                : $this->resolveClient((string) $this->option('client'), $integration->account_id)->id;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $project = Project::create([
            'account_id' => $integration->account_id,
            'client_id' => $clientId,
            'trello_board_id' => $boardId,
            'name' => $this->option('name') ?? $board['name'],
            'trello_url' => $board['url'] ?? null,
            'settings' => $board['workspace'] ? ['trello_workspace' => $board['workspace']] : [],
        ]);

        $this->info("✓ #{$project->id} {$project->name} adopted.");

        if ($clientId === null) {
            $this->warn('No client set — pass --client to file it, or it stays unassigned.');
        }

        $this->line('Run `php artisan trello:sync --sync --force` to pull its cards.');

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array{id: ?string, name: string, url: ?string, workspace: ?string}>  $boards
     * @param  \Illuminate\Support\Collection<string, Project>  $known
     */
    private function listBoards(\Illuminate\Support\Collection $boards, \Illuminate\Support\Collection $known): void
    {
        $rows = $boards->map(function (array $board) use ($known): array {
            $project = $known->get($board['id']);

            $state = match (true) {
                $project === null => 'not adopted',
                $project->isArchived() => 'archived',
                default => "→ #{$project->id}",
            };

            return [$board['id'], $board['name'], $board['workspace'] ?? '—', $state];
        })->all();

        $this->table(['Board ID', 'Name', 'Workspace', 'In CRM'], $rows);
        $this->line('Adopt one with: php artisan trello:adopt <board-id> --client="…"');
    }
}
