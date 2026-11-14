<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use App\Services\Messaging\SummaryEmails;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Make or refresh the month-end summary email draft of one client from an email template, filled with the month. A refresh replaces subject and body and keeps the recipients. Nothing here sends: only the owner sends it, in the app. A sent email is refused with not_a_draft. Say what you will write and wait for the owner before calling it.')]
final class SummaryEmailDraftTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'summary_email_draft';

    public function handle(Request $request, SummaryEmails $emails, CrmEntityResolver $resolver): Response
    {
        $v = $request->validate([
            'client' => ['required', 'string'],
            'month' => ['sometimes', 'nullable', 'string'],
            'template' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        try {
            $client = $resolver->client((string) $v['client'], $this->identity()->account->id);
            $email = $emails->draft($client, $v['month'] ?? null, $v['template'] ?? null);

            return $this->payload(['email' => [...$emails->present($email, withCandidates: true), 'untrusted' => ['subject', 'body']]]);
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
            'month' => $schema->string()->description('The month, YYYY-MM. Default: the previous month.'),
            'template' => $schema->string()->description('Email template key. Default: the one used last time, else the first.'),
        ];
    }
}
