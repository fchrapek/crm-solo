<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use App\Services\Messaging\SummaryEmails;
use Illuminate\Console\Command;

/** Agent verb: make or refresh a client's month-end summary email draft. Only the owner sends it, in the app. */
#[AccountScope(AccountScope::ACTING)]
final class CrmSummaryEmailDraft extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:summary-email-draft
                            {client : Client id or name fragment}
                            {--month= : The month YYYY-MM (default: previous month)}
                            {--template= : Email template key (default: the one used last time, else the first)}
                            {--json : Machine-readable output}';

    protected $description = 'Make or refresh the month-end summary email draft of a client (never sends)';

    public function handle(SummaryEmails $emails, CrmEntityResolver $resolver): int
    {
        try {
            $client = $resolver->client((string) $this->argument('client'), $this->actingIdentity()->account->id);
            $email = $emails->draft($client, $this->option('month') !== null ? (string) $this->option('month') : null, $this->option('template') ?: null);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        $payload = [...$emails->present($email, withCandidates: true), 'untrusted' => ['subject', 'body']];
        if ($this->wantsJson()) {
            $this->raw($this->encodeJson(['email' => $payload]));

            return self::SUCCESS;
        }

        $this->raw('Draft · '.$this->literal((string) $payload['client'])." · {$payload['period']} · to: ".($payload['recipients'] === [] ? 'nobody picked yet' : implode(', ', $payload['recipients'])));
        $this->fenced([(string) $payload['subject'], '', ...explode("\n", (string) $payload['body'])]);
        $this->raw('Only the owner sends it, in the app.');

        return self::SUCCESS;
    }
}
