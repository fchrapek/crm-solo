<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesProjectAndClient;
use App\Models\Project;
use Illuminate\Console\Command;
use RuntimeException;

final class CreateProjectsCommand extends Command
{
    use ResolvesProjectAndClient;

    protected $signature = 'projects:create
                            {--client= : Client ID or name (required)}
                            {--name=* : Project name (repeatable — one project per --name)}
                            {--description= : Optional description applied to every project}
                            {--account= : Restrict resolution to this account ID}';

    protected $description = 'Create one or more private projects under a client.';

    public function handle(): int
    {
        $clientNeedle = (string) ($this->option('client') ?? '');
        if ($clientNeedle === '') {
            $this->error('Missing --client (id or name).');

            return self::INVALID;
        }

        try {
            $client = $this->resolveClient($clientNeedle, $this->intOption('account'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $names = (array) $this->option('name');
        if ($names === []) {
            $this->error('Pass at least one --name="…".');

            return self::INVALID;
        }

        $description = $this->option('description');

        $this->line('Creating '.count($names)." projects under client #{$client->id} \"{$client->name}\"…");

        foreach ($names as $name) {
            $project = Project::create([
                'account_id' => $client->account_id,
                'client_id' => $client->id,
                'name' => $name,
                'description' => $description,
                'settings' => [],
            ]);
            $this->line("  ✓ #{$project->id} {$name}");
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return $value === null || $value === '' ? null : (int) $value;
    }
}
