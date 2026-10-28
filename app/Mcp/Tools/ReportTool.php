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
#[Description('One client report, by its id or by client and month. "hours" are the figures the client\'s Rozliczenia tab shows for that period (contracted, opening, available, used, forfeited, closing, change); "governs" is false when another report of the same period is the one the hours bank reads. Also status, composer_key, revision_count, version, body_markdown, edit_url, and the generated, finalized and sent times in ISO 8601 UTC. The body is listed under "untrusted": it can quote task titles written outside the CRM.')]
final class ReportTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'report';

    public function handle(Request $request, MonthCloseReader $reader, CrmEntityResolver $resolver): Response
    {
        $validated = $request->validate([
            'report' => ['nullable', 'integer', 'required_without:client', 'prohibits:client'],
            'client' => ['nullable', 'string', 'required_without:report'],
            'period' => ['nullable', 'string', 'prohibits:report'],
        ], [
            'report.required_without' => 'Pass a report id, or a client with an optional period.',
            'client.required_without' => 'Pass a report id, or a client with an optional period.',
        ]);

        $account = $this->identity()->account;

        try {
            return $this->payload(isset($validated['report'])
                ? $reader->reportById($account, (string) $validated['report'])
                : $reader->reportFor($resolver->client($validated['client'], $account->id), $validated['period'] ?? null));
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
            'report' => $schema->integer()->description('Report id. Not with client.'),
            'client' => $schema->string()->description('Client id or name fragment; returns its report for the period. Not with report.'),
            'period' => $schema->string()->description('With client: the month as YYYY-MM. Defaults to the previous month.'),
        ];
    }
}
