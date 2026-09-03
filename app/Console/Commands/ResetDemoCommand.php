<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'demo:reset', description: 'Wipe the database and reseed the fictional demo data (DEMO_MODE only)')]
final class ResetDemoCommand extends Command
{
    public function handle(): int
    {
        if (! config('app.demo')) {
            $this->error('demo:reset wipes the whole database — it only runs when DEMO_MODE=true.');

            return self::FAILURE;
        }

        $this->call('migrate:fresh', [
            '--seed' => true,
            '--seeder' => DemoSeeder::class,
            '--force' => true,
        ]);

        return self::SUCCESS;
    }
}
