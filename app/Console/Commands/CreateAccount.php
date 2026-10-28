<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Models\Account;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

#[AccountScope(AccountScope::OPERATOR)]
final class CreateAccount extends Command
{
    protected $signature = 'accounts:create
        {name : The business name}
        {--owner-email= : Login of the account owner}
        {--owner-first-name= : Owner first name}
        {--owner-last-name= : Owner last name}
        {--password-file= : File holding the owner password (first line); delete it afterwards}
        {--off=* : A module the account starts without (billing)}';

    protected $description = 'Create a new account with its owner user (operator command, works across accounts).';

    public function handle(): int
    {
        $name = mb_trim((string) $this->argument('name'));
        $email = mb_strtolower(mb_trim((string) $this->option('owner-email')));
        $first = mb_trim((string) $this->option('owner-first-name'));
        $last = mb_trim((string) $this->option('owner-last-name'));
        $file = (string) $this->option('password-file');

        if ($name === '' || $email === '' || $first === '' || $last === '' || $file === '') {
            $this->error('Pass the name, --owner-email, --owner-first-name, --owner-last-name and --password-file.');

            return self::FAILURE;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Not an email address: {$email}");

            return self::FAILURE;
        }

        // Local verbs act in the first account only while it is the only one.
        if (config('agent.local_account') === null && Account::query()->exists()) {
            $this->error('Set AGENT_LOCAL_ACCOUNT to the account local verbs act in (the operator, usually 1) before adding a second account.');

            return self::FAILURE;
        }

        // Soft-deleted users keep their email, and the column is unique across accounts.
        if (User::withTrashed()->where('email', $email)->exists()) {
            $this->error("A user with {$email} already exists; logins are unique across accounts.");

            return self::FAILURE;
        }

        $off = array_values(array_unique((array) $this->option('off')));
        $unknown = array_diff($off, Account::FEATURES);
        if ($unknown !== []) {
            $this->error('Unknown module '.implode(', ', $unknown).'; modules: '.implode(', ', Account::FEATURES).'.');

            return self::FAILURE;
        }

        $password = is_readable($file) ? mb_trim((string) strtok((string) file_get_contents($file), "\n")) : '';
        if (mb_strlen($password) < 12) {
            $this->error('The password file is missing, unreadable or holds fewer than 12 characters.');

            return self::FAILURE;
        }

        $account = DB::transaction(function () use ($name, $email, $first, $last, $password, $off): Account {
            $settings = $off === [] ? null : ['features' => array_fill_keys($off, false)];
            $account = Account::create(['name' => $name, 'is_test' => false, 'settings' => $settings]);

            // account_id and owner are not mass assignable.
            (new User)->forceFill([
                'account_id' => $account->id,
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'password' => Hash::make($password),
                'owner' => true,
                'email_verified_at' => now(),
            ])->save();

            return $account;
        });

        $this->info("Account #{$account->id} {$account->name} created; owner {$email}.");

        return self::SUCCESS;
    }
}
