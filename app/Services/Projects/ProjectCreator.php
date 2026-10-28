<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Models\Client;
use App\Models\Project;

/**
 * The one way a private project is created under a client: the client
 * screen's project form, `crm project-create` and MCP `project_create`.
 */
final class ProjectCreator
{
    public const array RULES = [
        'name' => 'required|string|max:255',
        'description' => 'nullable|string|max:5000',
    ];

    public function create(Client $client, string $name, ?string $description = null): Project
    {
        return Project::create([
            'account_id' => $client->account_id,
            'client_id' => $client->id,
            'name' => $name,
            'description' => $description,
        ]);
    }
}
