<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\TimeLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Log time after the fact as a closed entry. Target it with exactly one of task, client or project. Times are local wall-clock, not UTC.')]
final class LogTimeTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'log_time';

    public function handle(Request $request, TimeLogger $logger): Response
    {
        $validated = $request->validate([
            'minutes' => ['required', 'integer', 'min:1'],
            'task' => ['nullable', 'string'],
            'client' => ['nullable', 'string'],
            'project' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'title' => ['nullable', 'string'],
            'end' => ['nullable', 'string'],
            'billable' => ['nullable', 'boolean'],
        ], [
            'minutes.required' => 'Pass how many minutes to log.',
            'minutes.min' => 'Minutes must be a positive whole number.',
        ]);

        try {
            return $this->payload($logger->log(
                minutes: (int) $validated['minutes'],
                task: $validated['task'] ?? null,
                client: $validated['client'] ?? null,
                project: $validated['project'] ?? null,
                description: $validated['description'] ?? null,
                title: $validated['title'] ?? null,
                end: $validated['end'] ?? null,
                billable: $validated['billable'] ?? true,
                accountId: $this->identity()->account->id,
            ));
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgument($e->getMessage());
        }
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'minutes' => $schema->integer()
                ->description('How many minutes to log. The entry ends at "end" and starts that many minutes earlier.')
                ->required(),

            'task' => $schema->string()
                ->description('Task id or name fragment. Account, project and client are derived from it. Use exactly one of task, client or project.'),

            'client' => $schema->string()
                ->description('Client id or name fragment — logs against their General project with no task.'),

            'project' => $schema->string()
                ->description('Project id or name fragment — logs against the project with no task.'),

            'description' => $schema->string()
                ->description('What the time was spent on. Defaults to the task name, or "Ad-hoc work".'),

            'title' => $schema->string()
                ->description('Short label shown in the Time list.'),

            'end' => $schema->string()
                ->description('When the work ended, as local wall-clock "YYYY-MM-DD HH:MM" in the CRM display timezone. Defaults to now.'),

            'billable' => $schema->boolean()
                ->description('Whether the time is billable.')
                ->default(true),
        ];
    }
}
