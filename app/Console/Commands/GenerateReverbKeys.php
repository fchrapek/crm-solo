<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use Dotenv\Dotenv;
use Dotenv\Parser\Parser;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Fills REVERB_APP_KEY and REVERB_APP_SECRET in .env with random values, the
 * way key:generate fills APP_KEY, so no install runs on the values a public
 * .env.example would otherwise publish. Values are read the way Laravel reads
 * them (quotes, inline comments, CRLF, ${VAR} interpolation), and only the
 * assignment lines of these two keys are rewritten, with literal values.
 */
#[AccountScope(AccountScope::NONE)]
#[AsCommand(name: 'setup:reverb-keys', description: 'Generate a Reverb app key and secret in .env (only where blank, unless --force)')]
final class GenerateReverbKeys extends Command
{
    /** Values this repository has shipped in .env.example; treated as blank. */
    private const PUBLISHED = ['', 'laravel-reverb-key', 'secret'];

    protected $signature = 'setup:reverb-keys {--force : Replace values that are already set}';

    public function handle(): int
    {
        $path = $this->laravel->environmentFilePath();
        if (! is_file($path)) {
            $this->error("No environment file at {$path}. Copy .env.example first.");

            return self::FAILURE;
        }

        $contents = (string) file_get_contents($path);
        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        // Content lines at even indexes, their original line endings at odd ones.
        $parts = preg_split('/(\r\n|\n|\r)/', $contents, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [''];
        $changed = [];
        $resolved = $this->resolvedValues($contents);

        foreach (['REVERB_APP_KEY', 'REVERB_APP_SECRET'] as $name) {
            $lines = [];
            for ($i = 0; $i < count($parts); $i += 2) {
                if (preg_match('/^\s*(?:export\s+)?'.$name.'\s*=/', $parts[$i]) === 1) {
                    $lines[] = $i;
                }
            }

            // The value Laravel would load: interpolation resolved, and a later
            // assignment winning; the last line alone when the file does not parse.
            $current = $lines === [] ? null : (string) ($resolved !== null
                ? ($resolved[$name] ?? '')
                : $this->parsedValue($parts[end($lines)]));
            if ($current !== null && ! $this->option('force') && ! in_array($current, self::PUBLISHED, true)) {
                continue;
            }

            $assignment = $name.'='.Str::lower(Str::random(32));
            if ($lines === []) {
                $last = count($parts) - 1;
                if ($parts[$last] !== '') {
                    $parts[] = $eol;
                    $parts[] = '';
                    $last += 2;
                }
                $parts[$last] = $assignment;
                $parts[] = $eol;
                $parts[] = '';
            } else {
                foreach ($lines as $i) {
                    $parts[$i] = $assignment;
                }
            }
            $changed[] = $name;
        }

        if ($changed === []) {
            $this->info('Reverb key and secret are already set. Pass --force to replace them.');

            return self::SUCCESS;
        }

        file_put_contents($path, implode('', $parts));
        $this->info('Generated '.implode(' and ', $changed).'. Restart Reverb and Vite to pick them up.');

        return self::SUCCESS;
    }

    /**
     * Every variable of the file as a full dotenv load resolves it (nested
     * ${VAR} references included), or null when the file does not parse.
     *
     * @return array<string, string|null>|null
     */
    private function resolvedValues(string $contents): ?array
    {
        try {
            return Dotenv::parse($contents);
        } catch (Throwable) {
            return null;
        }
    }

    /** The value phpdotenv would load from this line; an unreadable line counts as blank. */
    private function parsedValue(string $line): string
    {
        try {
            $entries = (new Parser)->parse($line);
        } catch (Throwable) {
            return '';
        }

        $entry = $entries[0] ?? null;

        return $entry === null ? '' : $entry->getValue()->map(fn ($value) => $value->getChars())->getOrElse('');
    }
}
