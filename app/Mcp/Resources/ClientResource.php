<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\ResourceError;
use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\ClientBrief;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[MimeType('application/json')]
#[Description('One client\'s context, the same JSON the client_brief tool returns: status, retainer positions, hours this month, open tasks with their readiness, latest report and journal.')]
final class ClientResource extends Resource implements HasUriTemplate
{
    use HandlesReferenceErrors;

    protected string $name = 'client';

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('crm://clients/{id}');
    }

    public function handle(Request $request, CrmEntityResolver $resolver, ClientBrief $brief): Response
    {
        $id = (string) $request->get('id');
        if (! ctype_digit($id)) {
            throw ResourceError::invalidParams('A client resource takes a numeric id: crm://clients/42.', (string) $request->uri());
        }

        try {
            $client = $resolver->client($id, $this->identity()->account->id);
        } catch (ReferenceException $e) {
            throw ResourceError::notFound($e, (string) $request->uri());
        }

        return $this->payload($brief->for($client));
    }
}
