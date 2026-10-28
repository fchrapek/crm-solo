<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\MonthCloseReader;
use App\Services\Agent\ReferenceException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('What closing a client\'s month (Zamknij miesiąc) would freeze now, as the app\'s confirm dialog shows it: contracted, opening, cap, forfeited, used, closing (carried into "next"), source (report or entries), report_status, entries, the preview hash, and "refusal" saying why it cannot close (null when it can). Reads only: closing the month is the owner\'s action in the app.')]
final class MonthSettlementPreviewTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'month_settlement_preview';

    public function handle(Request $request, MonthCloseReader $reader, CrmEntityResolver $resolver): Response
    {
        $validated = $request->validate([
            'client' => ['required', 'string'],
            'period' => ['required', 'string'],
        ], [
            'client.required' => 'Pass a client id or a fragment of the client name.',
            'period.required' => 'Pass the month as YYYY-MM.',
        ]);

        try {
            return $this->payload($reader->settlementPreview($resolver->client($validated['client'], $this->identity()->account->id), $validated['period']));
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
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
            'client' => $schema->string()->description('Client id or name fragment.')->required(),
            'period' => $schema->string()->description('The month to close, as YYYY-MM.')->required(),
        ];
    }
}
