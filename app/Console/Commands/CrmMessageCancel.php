<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Messaging\MessageSettings;
use Illuminate\Console\Command;

/** Agent verb: stop a pending or failed message; it never goes out later. */
#[AccountScope(AccountScope::ACTING)]
final class CrmMessageCancel extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:message-cancel
        {id : Message id}
        {--json : Machine-readable output}';

    protected $description = 'Cancel a pending or failed text message';

    public function handle(MessageSettings $settings): int
    {
        $identity = $this->actingIdentity();

        try {
            $message = $settings->cancel($identity->account->id, (int) $this->argument('id'), $identity->user);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson(['id' => $message->id, 'status' => $message->status]));

            return self::SUCCESS;
        }

        $this->raw("Message #{$message->id} cancelled.");

        return self::SUCCESS;
    }
}
