<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Client;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $account = Account::create(['name' => 'Acme Corporation']);

        // The owner below has a password this repository publishes, and the
        // README tells a reader to run this seeder. Creating it anywhere but a
        // developer's own machine hands over an admin account. DemoSeeder
        // carries the same warning and generates a random password instead.
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('Skipping the known-password owner: not a local environment.');

            return;
        }

        User::firstOrCreate(
            ['email' => 'webmaster@crm-solo.test'],
            [
                'account_id' => $account->id,
                'first_name' => 'Local',
                'last_name' => 'Owner',
                'password' => Hash::make('test1234'),
                'owner' => true,
                'email_verified_at' => now(),
            ]
        );

        $clients = Client::factory(100)
            ->create(['account_id' => $account->id]);

        Contact::factory(100)
            ->create(['account_id' => $account->id])
            ->each(function ($contact) use ($clients) {
                $contact->update(['client_id' => $clients->random()->id]);
            });
    }
}
