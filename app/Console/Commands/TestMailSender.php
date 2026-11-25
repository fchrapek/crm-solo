<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Models\Account;
use App\Services\Messaging\Sender\SenderTests;
use Illuminate\Console\Command;

#[AccountScope(AccountScope::OPERATOR)]
final class TestMailSender extends Command
{
    protected $signature = 'mail-sender:test
        {--account= : The id of the account whose sender is tested}
        {--to= : Recipient (default: the sender\'s own from address)}';

    protected $description = "Send one test email through an account's own sender, at most 3 in 10 minutes (operator: names any account)";

    public static function throttleKey(int $accountId): string
    {
        return SenderTests::throttleKey($accountId);
    }

    public function handle(SenderTests $tests): int
    {
        if (! config('app.demo')) {
            $raw = (string) $this->option('account');
            if (! ctype_digit($raw) || ! Account::query()->whereKey((int) $raw)->exists()) {
                $this->error(__('Name the account with --account=<id> of an existing account.'));

                return self::FAILURE;
            }
        }

        $result = $tests->send((int) $this->option('account'), is_string($this->option('to')) ? $this->option('to') : null);
        $result['ok'] ? $this->info($result['message']) : $this->error($result['message']);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
