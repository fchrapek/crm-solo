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

#[Description('Recompose the body of a draft report and refresh its figures, as Regenerate on the report page. recalculate carries the opening balance in again from the settled month before. A finalized or sent report is refused with not_a_draft. Say what you will write and wait for the owner before calling it.')]
final class ReportRegenerateTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'report_regenerate';

    public function handle(Request $request, ReportEditor $editor): Response
    {
        $v = $request->validate([
            'report' => ['required', 'regex:/^\d+$/'],
            'composer' => ['sometimes', 'nullable', 'string', 'max:64'],
            'recalculate' => ['sometimes', 'boolean'],
            'locale' => ['sometimes', 'nullable', 'in:pl,en'],
        ]);

        return $this->write(fn (): array => $editor->regenerate(
            $this->identity()->account,
            (string) $v['report'],
            $v['composer'] ?? null,
            (bool) ($v['recalculate'] ?? false),
            $v['locale'] ?? 'pl',
        ));
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'report' => $schema->string()->description('Report id.')->required(),
            'composer' => $schema->string()->description('Composer key. Default: the one the report has.'),
            'recalculate' => $schema->boolean()->description('Carry the opening balance in again from the settled month before.'),
            'locale' => $schema->string()->description('Report language, pl (default) or en.'),
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
