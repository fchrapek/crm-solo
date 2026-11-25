<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\RetainerEditor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('End a retainer position from a date, the first day it no longer applies (default: the 1st of next month). Nothing is deleted; elapsed months keep their terms. Say what you will write and wait for the owner before calling it.')]
final class RetainerEndTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'retainer_end';

    public function handle(Request $request, RetainerEditor $editor): Response
    {
        $v = $request->validate([
            'client' => ['required', 'string'],
            'id' => ['required', 'integer', 'min:1'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        try {
            return $this->payload($editor->end($this->identity()->account, (string) $v['client'], (int) $v['id'], $v['to'] ?? null));
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
        } catch (AgentRefusal $e) {
            return $this->refusalError($e);
        }
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client' => $schema->string()->description('Client id or name fragment.')->required(),
            'id' => $schema->integer()->description('Position id from client_brief.')->required(),
            'to' => $schema->string()->description('First day it no longer applies, YYYY-MM-DD. Default: the 1st of next month.'),
        ];
    }
}
