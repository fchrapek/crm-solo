<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use App\Services\Messaging\MessageList;
use Illuminate\Console\Command;

/** Agent verb: queued and sent text messages, newest first. */
#[AccountScope(AccountScope::ACTING)]
final class CrmMessages extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:messages
        {--client= : Client id or name fragment}
        {--status= : pending, sent, simulated, failed, cancelled, skipped or superseded}
        {--limit=50 : At most this many}
        {--json : Machine-readable output}';

    protected $description = 'List text messages to clients: scheduled time, status, text';

    public function handle(CrmEntityResolver $resolver, MessageList $list): int
    {
        $accountId = $this->actingIdentity()->account->id;

        try {
            $clientId = $this->option('client') !== null ? $resolver->client((string) $this->option('client'), $accountId)->id : null;
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        }

        $messages = $list->for($accountId, $clientId, $this->option('status') !== null ? (string) $this->option('status') : null, (int) $this->option('limit'));

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson(['messages' => $messages]));

            return self::SUCCESS;
        }

        if ($messages === []) {
            $this->raw('No messages.');

            return self::SUCCESS;
        }

        $this->fenced(array_map(fn (array $m): string => "#{$m['id']} {$m['scheduled_for']} {$m['status']} ".$this->literal($m['client']).($m['body'] !== null ? ': '.$this->literal($m['body']) : '').($m['error'] !== null ? " ({$m['error']})" : ''), $messages));

        return self::SUCCESS;
    }
}
