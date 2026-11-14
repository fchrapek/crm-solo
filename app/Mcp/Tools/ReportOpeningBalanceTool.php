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

#[Description('Set the hours carried into the month of a draft report (negative for an overrun, null clears it); the billing summary in the body follows in the same write. Later drafts are flagged for recalculation. Say what you will write and wait for the owner before calling it.')]
final class ReportOpeningBalanceTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'report_opening_balance';

    public function handle(Request $request, ReportEditor $editor): Response
    {
        $v = $request->validate([
            'report' => ['required', 'regex:/^\d+$/'],
            'hours' => ['present', 'nullable', 'numeric', 'between:-9999,9999'],
        ]);

        return $this->write(fn (): array => $editor->openingBalance($this->identity()->account, (string) $v['report'], $v['hours'] !== null ? (float) $v['hours'] : null));
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'report' => $schema->string()->description('Report id.')->required(),
            'hours' => $schema->number()->description('Hours carried in; negative for an overrun; null clears it.')->required(),
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
