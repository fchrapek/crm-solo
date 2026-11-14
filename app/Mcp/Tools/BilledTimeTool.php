<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\BilledTimeEditor;
use App\Services\Agent\ReferenceException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('A client month in both values: "real" (the reportable time logged, never rounded) and "billed", the sum of its "tasks" (each the owner\'s "stated" figure with its internal "note", else its real time), most billed first. Also opening, available and the closing balance the hours bank reads, "source" of the used hours, the report, and "refusal" saying why the billed figure cannot change now (null when it can). Reads only.')]
final class BilledTimeTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'billed_time';

    public function handle(Request $request, BilledTimeEditor $editor): Response
    {
        $v = $request->validate([
            'client' => ['required', 'string'],
            'month' => ['sometimes', 'nullable', 'string'],
        ], ['client.required' => 'Pass a client id or a fragment of the client name.']);

        try {
            return $this->payload($editor->read($this->identity()->account, (string) $v['client'], $v['month'] ?? null));
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
            'month' => $schema->string()->description('The month as YYYY-MM; default the oldest month still open (the one a close is owed for).'),
        ];
    }
}
