<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Messaging\Sender\AccountSenders;
use Illuminate\Console\Command;

/** Agent verb: the account's own SMTP sender as settings and the last test result; the password shows only as set or not. */
#[AccountScope(AccountScope::ACTING)]
final class CrmMailSender extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:mail-sender {--json : Machine-readable output}';

    protected $description = "Show the account's own mail sender for lead notices: host, port, login, from, switch, last test; the password is set on the server only";

    public function handle(AccountSenders $senders): int
    {
        try {
            $sender = $senders->describe($this->actingIdentity()->account->id);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson(['sender' => $sender]));

            return self::SUCCESS;
        }

        if (! $sender['configured']) {
            $this->raw('No sender: lead notices wait until the owner sets one on the server (mail-sender:set).');

            return self::SUCCESS;
        }

        $test = $sender['last_test'];
        $this->raw('Sender: '.$this->literal((string) $sender['username']).' on '.$this->literal((string) $sender['host']).", port {$sender['port']}"
            .', from '.$this->literal(mb_trim(($sender['from_name'] ?? '').' <'.$sender['from_address'].'>'))
            .', '.($sender['enabled'] ? 'enabled' : 'disabled')
            .', password '.($sender['password_set'] ? ($sender['password_readable'] ? 'set' : 'set but unreadable') : 'not set')
            .', last test '.($test === null ? 'never' : ($test['ok'] ? "ok at {$test['at']}" : "failed ({$test['reason']}) at {$test['at']}")).'.');

        return self::SUCCESS;
    }
}
