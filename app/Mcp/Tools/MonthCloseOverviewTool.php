<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\MonthCloseReader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Every client in a period\'s month close, as the Zamknij miesiąc page lists them: clients in the monthly close, an unfinished run of the period, and clients still owing that hours month. Each has "checklist" (status not_started, open or completed, resolved, total, steps; null when the client has no checklist), "report" (mode report, summary_email or none, and the period\'s report_id and status, null without one) and "settlement" (state ready, blocked when an earlier month must close first, or closed; active_period, closed_on, closing; null when the month has nothing to settle). Never starts a run.')]
final class MonthCloseOverviewTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'month_close_overview';

    public function handle(Request $request, MonthCloseReader $reader): Response
    {
        $validated = $request->validate([
            'period' => ['nullable', 'string'],
        ]);

        try {
            return $this->payload($reader->overview($this->identity()->account, $validated['period'] ?? null));
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
            'period' => $schema->string()
                ->description('Billed month as YYYY-MM. Defaults to the previous month, which is the one normally being closed.'),
        ];
    }
}
