<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Messaging\MessageSettings;
use Illuminate\Console\Command;

/** Agent verb: the account's quiet hours, whether Polish letters are spelled without diacritics, and the provider that sends. */
#[AccountScope(AccountScope::ACTING)]
final class CrmMessageSending extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:message-sending
        {--quiet-from= : Quiet hours start, local HH:MM}
        {--quiet-to= : Quiet hours end, local HH:MM}
        {--ascii : Spell Polish letters without diacritics (160 characters per SMS)}
        {--unicode : Keep Polish letters (70 characters per SMS)}
        {--provider= : simulator (nothing leaves) or smsapi (real messages, needs SMSAPI connected in Integrations)}
        {--json : Machine-readable output}';

    protected $description = 'Show or change quiet hours, the Polish letters setting and the provider for text messages';

    public function handle(MessageSettings $settings): int
    {
        try {
            $sending = $settings->sending(
                $this->actingIdentity()->account->id,
                $this->option('quiet-from'),
                $this->option('quiet-to'),
                $this->option('ascii') ? true : ($this->option('unicode') ? false : null),
                $this->option('provider'),
            );
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson(['sending' => $sending]));

            return self::SUCCESS;
        }

        $this->raw("Quiet hours {$sending['quiet_from']} to {$sending['quiet_to']}; Polish letters ".($sending['ascii'] ? 'spelled without diacritics' : 'kept')."; sent by {$sending['provider']}.");

        return self::SUCCESS;
    }
}
