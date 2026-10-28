<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Client;
use App\Models\Project;
use App\Services\Projects\ProjectCreator;
use App\Support\LegalName;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

/**
 * A new project from `crm project-create` and MCP `project_create`: the client
 * resolved inside the account, the project form's rules, and a refusal when
 * the client already has a live project of that name unless `force`.
 */
final readonly class ProjectIntake
{
    public function __construct(
        private CrmEntityResolver $resolver,
        private ProjectCreator $creator,
    ) {}

    /** Lower case, Polish letters as ASCII, words joined by one space, punctuation dropped. */
    public static function key(string $name): string
    {
        $key = implode(' ', array_column(LegalName::words($name), 'fold'));

        return $key !== '' ? $key : mb_strtolower(mb_trim($name));
    }

    /**
     * @param  array{client?: ?string, name?: ?string, description?: ?string, force?: ?bool}  $input
     * @return array<string, mixed>
     *
     * @throws AgentRefusal|ReferenceException
     */
    public function create(int $accountId, array $input): array
    {
        $needle = mb_trim((string) ($input['client'] ?? ''));
        if ($needle === '') {
            throw AgentRefusal::invalid('Pass the client (id or name fragment) the project belongs to.', ['field' => 'client']);
        }

        $name = mb_trim((string) ($input['name'] ?? ''));
        $description = mb_trim((string) ($input['description'] ?? ''));
        $attributes = ['name' => $name, 'description' => $description === '' ? null : $description];
        $validator = Validator::make($attributes, ProjectCreator::RULES);
        if ($validator->fails()) {
            throw AgentRefusal::invalid('The project was not created: '.implode(' ', $validator->errors()->all()), ['errors' => $validator->errors()->toArray()]);
        }

        $client = $this->resolver->client($needle, $accountId);
        $force = (bool) ($input['force'] ?? false);

        // Two calls for the same name at once must not both pass the guard.
        return Cache::lock("agent:project-create:{$accountId}", 10)->block(5, fn (): array => $this->guardAndCreate($client, $name, $attributes['description'], $force));
    }

    /**
     * @return array<string, mixed>
     */
    private function guardAndCreate(Client $client, string $name, ?string $description, bool $force): array
    {
        if (! $force) {
            $key = self::key($name);
            $matches = $client->projects()->notArchived()->orderBy('id')->get(['id', 'name'])
                ->filter(fn (Project $project): bool => self::key((string) $project->name) === $key)
                ->values();
            if ($matches->isNotEmpty()) {
                throw new AgentRefusal(
                    AgentRefusal::POSSIBLE_DUPLICATE,
                    "Client #{$client->id} already has a project named like \"{$name}\". Use it, or call again with force to create a second one.",
                    ['candidates' => $matches->map(fn (Project $project): array => [
                        'id' => (int) $project->id,
                        'name' => (string) $project->name,
                        'context' => (string) $client->name,
                    ])->all(), 'untrusted' => ['candidates']],
                );
            }
        }

        $project = $this->creator->create($client, $name, $description);

        return [
            'project' => [
                'id' => (int) $project->id,
                'name' => (string) $project->name,
                'description' => $project->description,
                'client_id' => (int) $client->id,
                'client' => (string) $client->name,
            ],
            'forced' => $force,
        ];
    }
}
