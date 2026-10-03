<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Client;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $account = Account::create(['name' => 'Acme Corporation']);

        // The demo seeds its own login (DemoSeeder) and nothing else may exist there.
        if (config('app.demo')) {
            $this->command?->warn('Skipping the local owner: DEMO_MODE is on, DemoSeeder owns the login.');

            return;
        }

        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('Skipping the local owner: not a local environment.');

            return;
        }

        // A published constant would be a working login on every fresh clone.
        $configured = (string) config('app.seed_owner_password');
        $password = $configured !== '' ? $configured : Str::password(20, symbols: false);

        $owner = User::withTrashed()->firstOrNew(['email' => 'webmaster@crm-solo.test']);
        $created = ! $owner->exists;

        if ($created) {
            // account_id and owner are not mass assignable.
            $owner->forceFill([
                'account_id' => $account->id,
                'first_name' => 'Local',
                'last_name' => 'Owner',
                'password' => Hash::make($password),
                'owner' => true,
                'email_verified_at' => now(),
            ])->save();
        }

        if (! $created) {
            $this->command?->info('Local owner webmaster@crm-solo.test already exists; its password is unchanged.');
        } elseif ($configured !== '') {
            $this->command?->info('Local owner webmaster@crm-solo.test seeded with SEED_OWNER_PASSWORD.');
        } else {
            $this->command?->info('Local owner seeded. This password is shown once:');
            $this->command?->info('  Login:    webmaster@crm-solo.test');
            $this->command?->info("  Password: {$password}");
        }

        $clients = Client::factory(100)
            ->create(['account_id' => $account->id]);

        Contact::factory(100)
            ->create(['account_id' => $account->id])
            ->each(function ($contact) use ($clients) {
                $contact->update(['client_id' => $clients->random()->id]);
            });
    }
}
