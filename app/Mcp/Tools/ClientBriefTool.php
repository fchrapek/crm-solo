<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\ClientBrief;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Full context for one client: relationship status, retainer positions, hours, open tasks, latest report and recent journal. hours.month is all time logged this calendar month, reportable or not. hours.bank is the month being settled (hours.bank.active_period, the oldest elapsed month not yet closed, else the current month; open_period_behind is true when that month has ended), counted on reportable time: the retainer limit applies to hours.bank.used against hours.bank.available. hours.bank.closed_period and closed_at name the latest closed month. billing_mode is retainer or order; an order client (no retainer evidence) has hours.bank and hours.contracted null. Closing a month is done by the owner in the app; there is no tool for it.')]
final class ClientBriefTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'client_brief';

    public function handle(Request $request, CrmEntityResolver $resolver, ClientBrief $brief): Response
    {
        $validated = $request->validate([
            'client' => ['required', 'string'],
        ], [
            'client.required' => 'Pass a client id or a fragment of the client name, for example "42" or "Acme".',
        ]);

        try {
            $client = $resolver->client($validated['client'], $this->identity()->account->id);
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
        }

        return $this->payload($brief->for($client));
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client' => $schema->string()
                ->description('Client id, or a fragment of the client name.')
                ->required(),
        ];
    }
}
