<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Models\Client;
use Illuminate\Console\Command;

/**
 * Give every live client without a private project its "General" one, the
 * project a client-level time entry lands on. New clients get it on create;
 * this covers clients created before every path did. Dry run unless --apply.
 */
#[AccountScope(AccountScope::OPERATOR)]
final class EnsureGeneralProjects extends Command
{
    protected $signature = 'clients:ensure-general-project
                            {--account= : Restrict to this account ID}
                            {--apply : Create the projects (default is a dry run)}';

    protected $description = 'Create the missing General project for clients that have no private project (dry run unless --apply) (operator: every account, or the one named by --account)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $clients = Client::query()
            ->when($this->option('account') !== null, fn ($query) => $query->where('account_id', (int) $this->option('account')))
            ->whereDoesntHave('projects', fn ($query) => $query->whereNull('trello_board_id'))
            ->orderBy('id')
            ->get();

        if ($clients->isEmpty()) {
            $this->info('Every client has a General project.');

            return self::SUCCESS;
        }

        $this->line(($apply ? 'Creating' : 'Would create').' a General project for '.$clients->count().' clients:');

        foreach ($clients as $client) {
            if ($apply) {
                $project = $client->ensureGeneralProject();
                $this->line("  #{$client->id} {$client->name} -> project #{$project->id}");
            } else {
                $this->line("  #{$client->id} {$client->name}");
            }
        }

        if (! $apply) {
            $this->warn('Dry run. Re-run with --apply to create them.');
        }

        return self::SUCCESS;
    }
}
