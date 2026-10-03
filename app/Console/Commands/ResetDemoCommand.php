<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Models\Account;
use Database\Seeders\DemoSeeder;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AccountScope(AccountScope::OPERATOR)]
#[AsCommand(name: 'demo:reset', description: 'Wipe the database and uploaded files, then reseed the fictional demo data (DEMO_MODE or DEMO_RESET_ALLOWED only; operator: every account)')]
final class ResetDemoCommand extends Command
{
    /** Visitor uploads on the local disk; nothing else there is touched. */
    private const UPLOAD_DIRECTORIES = ['task-attachments', 'client-documents'];

    public function handle(): int
    {
        if (! config('app.demo') && ! config('app.demo_reset_allowed')) {
            $this->error('demo:reset wipes the whole database. It only runs when DEMO_MODE=true or DEMO_RESET_ALLOWED=true.');

            return self::FAILURE;
        }

        // A DEMO_MODE flag set by mistake on a real install must not cost it
        // its data or its files: every account here has to be a demo account.
        $refusal = $this->databaseRefusal();
        if ($refusal !== null) {
            $this->error("demo:reset refused: {$refusal}");

            return self::FAILURE;
        }

        try {
            foreach ($this->purgeTargets() as $directory) {
                $this->purge($directory);
            }
        } catch (RuntimeException $e) {
            $this->error("demo:reset refused: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->call('migrate:fresh', [
            '--seed' => true,
            '--seeder' => DemoSeeder::class,
            '--force' => true,
        ]);

        return self::SUCCESS;
    }

    private function databaseRefusal(): ?string
    {
        try {
            // A first install: the connection's own database holds no table at
            // all (SQLite: nothing in `main` outside sqlite_*; MariaDB: no base
            // table in the configured database), so there is nothing to protect.
            if (Schema::getTableListing(Schema::getCurrentSchemaName(), schemaQualified: false) === []) {
                return null;
            }

            if (! Schema::hasTable('accounts')) {
                return 'the database has tables but no accounts table, so it cannot be shown to hold demo data.';
            }

            if (Account::query()->where('is_test', false)->exists()) {
                return 'this database holds an account that is not a demo account.';
            }
        } catch (Throwable) {
            return 'the database could not be read.';
        }

        return null;
    }

    /**
     * Every check runs before anything is deleted: each upload directory must
     * be a real directory, not a link, whose resolved path sits inside the
     * resolved disk root.
     *
     * @return list<string>
     */
    private function purgeTargets(): array
    {
        $root = realpath(Storage::disk('local')->path(''));
        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('the local disk root does not exist.');
        }

        // A disk root pointed somewhere else by mistake is not purged at all.
        $storage = realpath(storage_path());
        if ($storage === false || ! str_starts_with($root, $storage.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("the local disk root {$root} is not inside the storage folder.");
        }

        $targets = [];
        foreach (self::UPLOAD_DIRECTORIES as $name) {
            $path = $root.DIRECTORY_SEPARATOR.$name;
            if (is_link($path)) {
                throw new RuntimeException("{$name} is a symbolic link.");
            }
            if (! file_exists($path)) {
                continue;
            }

            $resolved = realpath($path);
            if ($resolved === false || ! is_dir($resolved) || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException("{$name} does not resolve to a directory inside the disk root.");
            }
            $targets[] = $resolved;
        }

        return $targets;
    }

    /** Child-first delete that removes links themselves and never descends into them. */
    private function purge(string $directory): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $path = $entry->getPathname();
            $removed = $entry->isDir() && ! $entry->isLink() ? @rmdir($path) : @unlink($path);
            if (! $removed) {
                throw new RuntimeException("could not delete {$path}.");
            }
        }

        if (! @rmdir($directory)) {
            throw new RuntimeException("could not delete {$directory}.");
        }
    }
}
