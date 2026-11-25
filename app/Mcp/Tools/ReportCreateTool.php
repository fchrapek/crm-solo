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

#[Description('Generate a draft report for the month of one client, the same as Generate on the Billing tab. A month that already has a report is refused with report_exists and its id: regenerate or edit that one. hours, when given, must equal the month\'s task sum; to bill a different total, set a task figure with task_billed_set first. Say what you will write and wait for the owner before calling it.')]
final class ReportCreateTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'report_create';

    public function handle(Request $request, ReportEditor $editor): Response
    {
        $v = $request->validate([
            'client' => ['required', 'string'],
            'period' => ['sometimes', 'nullable', 'string'],
            'hours' => ['sometimes', 'nullable', 'numeric'],
            'composer' => ['sometimes', 'nullable', 'string', 'max:64'],
            'locale' => ['sometimes', 'nullable', 'in:pl,en'],
        ]);

        return $this->write(fn (): array => $editor->create(
            $this->identity()->account,
            (string) $v['client'],
            $v['period'] ?? null,
            $v['composer'] ?? null,
            isset($v['hours']) ? (float) $v['hours'] : null,
            $v['locale'] ?? 'pl',
        ));
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client' => $schema->string()->description('Client id or name fragment.')->required(),
            'period' => $schema->string()->description('The month, YYYY-MM. Default: the previous month.'),
            'hours' => $schema->number()->description('Billed hours; they must be the sum of the task figures (set those with task_billed_set).'),
            'composer' => $schema->string()->description('Composer key. Default: the account default.'),
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
