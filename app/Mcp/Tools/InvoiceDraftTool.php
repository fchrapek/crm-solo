<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use App\Services\Integrations\InfaktApiException;
use App\Services\Integrations\InfaktDrafting;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

#[Description('Create Infakt DRAFT invoices for the billed month of a maintenance client, one per invoice group (named by its projects): from the retainer (default), or with like = "last" as a copy of the invoice recorded for each group in the latest earlier month, with new dates (Wystaw podobna). A group with no such invoice comes back needs_source while the others draft; the owner picks its invoice once with like = an invoice number and project, and later months follow it. Draft only: nothing is issued, sent or marked paid. Call it first with dry_run true and show the owner the drafts; send only after they agree. Each group is drafted once a month: a second call says already_drafted.')]
final class InvoiceDraftTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'invoice_draft';

    public function handle(Request $request, InfaktDrafting $drafting, CrmEntityResolver $resolver): Response
    {
        $v = $request->validate([
            'client' => ['required', 'string'],
            'period' => ['sometimes', 'nullable', 'string'],
            'like' => ['sometimes', 'nullable', 'string', 'max:64'],
            'project' => ['sometimes', 'nullable', 'string', 'max:255'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        try {
            $accountId = $this->identity()->account->id;
            $client = $resolver->client((string) $v['client'], $accountId);
            $project = isset($v['project']) ? $resolver->project(mb_trim($v['project']), $accountId, $client->id) : null;
            $period = $v['period'] ?? null;
            $like = isset($v['like']) ? mb_trim($v['like']) : null;
            $result = ($v['dry_run'] ?? false) ? $drafting->preview($client, $period, $like, $project) : $drafting->draft($client, $period, $like, project: $project);

            return $this->payload(['client_id' => $client->id, 'dry_run' => (bool) ($v['dry_run'] ?? false), ...$result]);
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
        } catch (InfaktApiException $e) {
            return $this->refusalError(new AgentRefusal(AgentRefusal::REFUSED, "Infakt said no: {$e->userMessage()}."));
        } catch (RuntimeException $e) {
            return $this->refusalError(new AgentRefusal(AgentRefusal::REFUSED, $e->getMessage()));
        }
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client' => $schema->string()->description('Client id or name fragment.')->required(),
            'period' => $schema->string()->description('The billed month, YYYY-MM. Default: the previous month.'),
            'like' => $schema->string()->description('"last" to copy each group\'s previous invoice, or an Infakt invoice number to pick the source of one group. Omit to draft from the retainer.'),
            'project' => $schema->string()->description('Project id or name fragment: only the invoice group of this project. Needed with an invoice number when the client has several groups.'),
            'dry_run' => $schema->boolean()->description('true shows the draft without sending anything.'),
        ];
    }
}
