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

        // This owner's password is published in the repository, so it must
        // never exist anywhere but a developer's own machine.
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
