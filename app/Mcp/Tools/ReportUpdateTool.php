<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\ReportEditor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Replace the whole body of a draft report (Markdown). Pass the version the report tool returned; a newer edit since then is refused. A finalized or sent report is refused with not_a_draft. Show the owner the change first. Say what you will write and wait for the owner before calling it.')]
final class ReportUpdateTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'report_update';

    public function handle(Request $request, ReportEditor $editor): Response
    {
        $v = $request->validate([
            'report' => ['required', 'regex:/^\d+$/'],
            'body' => ['required', 'string', 'max:200000'],
            'version' => ['required', 'string'],
        ]);

        return $this->write(fn (): array => $editor->updateBody($this->identity()->account, (string) $v['report'], $v['body'], $v['version']));
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'report' => $schema->string()->description('Report id.')->required(),
            'body' => $schema->string()->description('The whole new body, Markdown.')->required(),
            'version' => $schema->string()->description('The version the report tool returned.')->required(),
        ];
    }

    /** @param  callable(): array<string, mixed>  $write */
    private function write(callable $write): Response
    {
        try {
            return $this->payload($write());
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
        } catch (AgentRefusal $e) {
            return $this->refusalError($e);
        }
    }
}
