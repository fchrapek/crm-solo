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

#[Description('Add a retainer (abonament) position to a client, or with id change only the fields given (null clears one). Positions are listed with their ids by client_brief. A change that would alter a month already closed or being settled is refused: change it from the next month on. Say what you will write and wait for the owner before calling it.')]
final class RetainerSetTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'retainer_set';

    public function handle(Request $request, RetainerEditor $editor): Response
    {
        $v = $request->validate([
            'client' => ['required', 'string'],
            'id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        $fields = array_intersect_key($request->all(), array_flip(RetainerEditor::FIELDS));

        try {
            return $this->payload($editor->set($this->identity()->account, (string) $v['client'], isset($v['id']) ? (int) $v['id'] : null, $fields));
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
            'id' => $schema->integer()->description('Position id to change; omit to add a new position.'),
            'monthly_hours' => $schema->number()->description('Monthly hours; required for a new position.'),
            'monthly_fee' => $schema->number()->description('Monthly net fee.'),
            'overage_hourly_rate' => $schema->number()->description('Hourly rate over the pool.'),
            'rollover_cap_hours' => $schema->number()->description('Most hours carried to the next month.'),
            'label' => $schema->string()->description('Name, as on the invoice line.'),
            'description' => $schema->string()->description('Longer description.'),
            'project_id' => $schema->integer()->description('Project id the position covers.'),
            'invoice_group' => $schema->integer()->description('Invoice group, 1 and up; positions in one group share an invoice.'),
            'vat_symbol' => $schema->string()->description('VAT symbol, e.g. "23".'),
            'currency' => $schema->string()->description('Three-letter currency.'),
            'effective_from' => $schema->string()->description('First day it applies, YYYY-MM-DD. New position default: today.'),
            'notes' => $schema->string()->description('Internal notes.'),
        ];
    }
}
