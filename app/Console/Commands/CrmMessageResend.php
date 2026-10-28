<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Messaging\MessageSettings;
use Illuminate\Console\Command;

/** Agent verb: send a failed message again, rebuilt from the client as it is now. */
#[AccountScope(AccountScope::ACTING)]
final class CrmMessageResend extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:message-resend
        {id : Message id}
        {--json : Machine-readable output}';

    protected $description = 'Send a failed text message again';

    public function handle(MessageSettings $settings): int
    {
        $identity = $this->actingIdentity();

        try {
            $message = $settings->resend($identity->account->id, (int) $this->argument('id'));
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson(['id' => $message->id, 'status' => $message->status, 'resends' => $message->resends, 'previous_error' => $message->previous_error]));

            return self::SUCCESS;
        }

        $this->raw("Message #{$message->id} queued again; the next dispatch sends it.");

        return self::SUCCESS;
    }
}
