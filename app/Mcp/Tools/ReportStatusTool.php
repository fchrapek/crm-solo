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

#[Description('Move a report: finalize it, mark it sent, or reopen it as a draft, as the buttons on the report page. Finalize is refused while a timer runs in the month or the report needs recalculation. Say what you will write and wait for the owner before calling it.')]
final class ReportStatusTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'report_status';

    public function handle(Request $request, ReportEditor $editor): Response
    {
        $v = $request->validate([
            'report' => ['required', 'regex:/^\d+$/'],
            'action' => ['required', 'in:'.implode(',', ReportEditor::STATUS_ACTIONS)],
        ]);

        return $this->write(fn (): array => $editor->status($this->identity()->account, (string) $v['report'], $v['action']));
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'report' => $schema->string()->description('Report id.')->required(),
            'action' => $schema->string()->enum(ReportEditor::STATUS_ACTIONS)->description('finalize, sent or reopen.')->required(),
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
