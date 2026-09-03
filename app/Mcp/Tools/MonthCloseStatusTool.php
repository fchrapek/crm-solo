<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\MonthCloseTicker;
use App\Services\Agent\ReferenceException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('The month-close checklist for a client and period, with each step and its state. Reading a period that was never started returns the template it would seed, without starting it.')]
final class MonthCloseStatusTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'month_close_status';

    public function handle(Request $request, CrmEntityResolver $resolver, MonthCloseTicker $ticker): Response
    {
        $validated = $request->validate([
            'client' => ['required', 'string'],
            'period' => ['nullable', 'string'],
        ], [
            'client.required' => 'Pass a client id or a fragment of the client name.',
        ]);

        try {
            $client = $resolver->client($validated['client'], $this->identity()->account->id);
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
        }

        try {
            return $this->payload($ticker->status($client, $validated['period'] ?? $ticker->defaultPeriod()));
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgument($e->getMessage());
        }
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

            'period' => $schema->string()
                ->description('Billed month as YYYY-MM. Defaults to the previous month, which is the one normally being closed.'),
        ];
    }
}
