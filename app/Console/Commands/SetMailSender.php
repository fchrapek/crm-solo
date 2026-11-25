<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Exceptions\SenderUnavailable;
use App\Models\Account;
use App\Services\Messaging\Sender\AccountSenders;
use App\Support\SecretInput;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[AccountScope(AccountScope::OPERATOR)]
final class SetMailSender extends Command
{
    protected $signature = 'mail-sender:set
        {--account= : The id of the account the sender belongs to}
        {--host= : SMTP server name; its certificate must be issued for it}
        {--port=587 : 465 (TLS) or 587 or 2525 (STARTTLS)}
        {--username= : SMTP login}
        {--from= : From address}
        {--from-name= : From name}
        {--keep-password : Keep the stored password instead of reading one from stdin}';

    protected $description = "Set an account's own SMTP sender for lead notices; the password comes on stdin only (operator: names any account)";

    public function handle(AccountSenders $senders, SecretInput $input): int
    {
        if (config('app.demo')) {
            $this->error(__('The demo has no senders. Nothing was changed.'));

            return self::FAILURE;
        }

        $raw = (string) $this->option('account');
        if (! ctype_digit($raw) || ! Account::query()->whereKey((int) $raw)->exists()) {
            $this->error(__('Name the account with --account=<id> of an existing account.'));

            return self::FAILURE;
        }
        $accountId = (int) $raw;

        $port = (string) $this->option('port');
        $fields = [
            'host' => (string) $this->option('host'),
            'port' => ctype_digit($port) ? (int) $port : 0,
            'username' => (string) $this->option('username'),
            'from_address' => mb_trim((string) $this->option('from')),
            'from_name' => is_string($this->option('from-name')) ? $this->option('from-name') : null,
        ];

        try {
            $password = $this->option('keep-password') ? null : $input->line();
            if (! $this->option('keep-password') && ($password === null || $password === '')) {
                throw new InvalidArgumentException(__('No password came in on stdin: pipe it (docker compose exec -T), or pass --keep-password to keep the stored one.'));
            }
            $row = $senders->save($accountId, $fields, $password);
        } catch (InvalidArgumentException|SenderUnavailable $e) {
            $this->error(__(':reason Nothing was changed.', ['reason' => $e->getMessage()]));

            return self::FAILURE;
        }

        $settings = $row->settings ?? [];
        $this->info(__($password === null
            ? 'Sender of account :account: :username on :host, port :port, from :from. The stored password was kept.'
            : 'Sender of account :account: :username on :host, port :port, from :from. The password is stored encrypted.', [
                'account' => $accountId,
                'username' => $settings['username'],
                'host' => $settings['host'],
                'port' => $settings['port'],
                'from' => $settings['from_name'] !== null ? "{$settings['from_name']} <{$settings['from_address']}>" : $settings['from_address'],
            ]));

        return self::SUCCESS;
    }
}
