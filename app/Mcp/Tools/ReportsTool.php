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
#[Description('A client\'s reports, newest first, without bodies: id, period, period_start, period_end, whole_month, status, governs (the one the hours bank reads for its period end), needs_recalculation, composer_key, generated_at, finalized_at, sent_at, sent_on and edit_url. Read one with "report".')]
final class ReportsTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'reports';

    public function handle(Request $request, MonthCloseReader $reader, CrmEntityResolver $resolver): Response
    {
        $validated = $request->validate([
            'client' => ['required', 'string'],
        ], [
            'client.required' => 'Pass a client id or a fragment of the client name.',
        ]);

        try {
            return $this->payload($reader->reports($resolver->client($validated['client'], $this->identity()->account->id)));
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
        ];
    }
}
