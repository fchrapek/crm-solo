<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\Client;
use App\Models\Project;
use RuntimeException;

trait ResolvesProjectAndClient
{
    protected function resolveProject(string $needle, ?int $accountId = null): Project
    {
        $query = Project::query();

        if ($accountId !== null) {
            $query->where('account_id', $accountId);
        }

        if (ctype_digit($needle)) {
            $project = $query->find((int) $needle);
            if (! $project) {
                throw new RuntimeException("Project #{$needle} not found".($accountId ? " in account #{$accountId}" : '').'.');
            }

            return $project;
        }

        $matches = $query->where('name', 'like', "%{$needle}%")->get();
        if ($matches->isEmpty()) {
            throw new RuntimeException("No project matches \"{$needle}\".");
        }
        if ($matches->count() > 1) {
            $list = $matches->map(fn (Project $p) => "  #{$p->id} \"{$p->name}\" (account {$p->account_id})")->implode("\n");
            throw new RuntimeException("Ambiguous — \"{$needle}\" matches multiple projects:\n{$list}\nPass the numeric ID or refine the name.");
        }

        return $matches->first();
    }

    protected function resolveClient(string $needle, ?int $accountId = null): Client
    {
        $query = Client::query();

        if ($accountId !== null) {
            $query->where('account_id', $accountId);
        }

        if (ctype_digit($needle)) {
            $client = $query->find((int) $needle);
            if (! $client) {
                throw new RuntimeException("Client #{$needle} not found".($accountId ? " in account #{$accountId}" : '').'.');
            }

            return $client;
        }

        $matches = $query->where('name', 'like', "%{$needle}%")->get();
        if ($matches->isEmpty()) {
            throw new RuntimeException("No client matches \"{$needle}\".");
        }
        if ($matches->count() > 1) {
            $list = $matches->map(fn (Client $c) => "  #{$c->id} \"{$c->name}\" (account {$c->account_id})")->implode("\n");
            throw new RuntimeException("Ambiguous — \"{$needle}\" matches multiple clients:\n{$list}\nPass the numeric ID or refine the name.");
        }

        return $matches->first();
    }
}
