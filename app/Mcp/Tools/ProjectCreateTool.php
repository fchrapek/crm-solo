<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ProjectIntake;
use App\Services\Agent\ReferenceException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add a project under a client, the same as the project form on the client screen; tasks can then be created on it or moved onto it with task_edit. A name the client already uses for a live project (case and Polish letters ignored) is refused with "possible_duplicate" and the matches: usually use that project instead, and pass force only once the user confirms a second one is wanted. Confirm the client and the name with the user before calling.')]
final class ProjectCreateTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'project_create';

    public function handle(Request $request, ProjectIntake $intake): Response
    {
        $validated = $request->validate([
            'client' => ['required', 'string'],
            'name' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'force' => ['nullable', 'boolean'],
        ], [
            'client.required' => 'Pass the client the project belongs to.',
            'name.required' => 'Pass the project name.',
        ]);

        try {
            return $this->payload($intake->create($this->identity()->account->id, $validated));
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
        } catch (AgentRefusal $e) {
            return $this->refusalError($e);
        }
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client' => $schema->string()->description('Client id or name fragment.')->required(),
            'name' => $schema->string()->description('Project name.')->required(),
            'description' => $schema->string()->description('What the project is.'),
            'force' => $schema->boolean()->description('Create it even when the client has a live project of that name. Only after the user confirms.'),
        ];
    }
}
