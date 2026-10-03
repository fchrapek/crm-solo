<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Models\Account;
use App\Models\Setting;
use App\Services\Reports\SettingsNarrativePromptResolver;
use Illuminate\Console\Command;

/**
 * Stores an account's own report prompt in the settings table, overriding the
 * prompt shipped in resources/prompts.
 *
 * This is how an instance keeps wording it does not want in the repository:
 * the file stays wherever the owner keeps it, only the database carries it.
 */
#[AccountScope(AccountScope::ACTING)]
final class ReportPromptImport extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'reports:prompt-import
        {path : Markdown file holding the prompt}
        {--account= : Account id; must be the acting account (the default)}
        {--clear : Remove the override and fall back to the shipped prompt}';

    protected $description = 'Import a report narrative prompt into the settings override.';

    public function handle(): int
    {
        $accountId = $this->resolveAccountId();

        if ($accountId === null) {
            return self::FAILURE;
        }

        if ($this->option('clear')) {
            return $this->clear($accountId);
        }

        $path = (string) $this->argument('path');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read [{$path}].");

            return self::FAILURE;
        }

        $prompt = (string) file_get_contents($path);

        if (mb_trim($prompt) === '') {
            $this->error("[{$path}] is empty.");

            return self::FAILURE;
        }

        $this->write($accountId, $prompt);

        $this->info(sprintf(
            'Imported %d characters into the reports settings for account %d.',
            mb_strlen($prompt),
            $accountId,
        ));

        return self::SUCCESS;
    }

    private function clear(int $accountId): int
    {
        $this->write($accountId, null);

        $this->info("Override cleared for account {$accountId}; the shipped prompt applies.");

        return self::SUCCESS;
    }

    private function write(int $accountId, ?string $prompt): void
    {
        $setting = Setting::query()->firstOrNew([
            'account_id' => $accountId,
            'scope' => SettingsNarrativePromptResolver::SETTING_SCOPE,
        ]);

        $data = is_array($setting->data) ? $setting->data : [];

        if ($prompt === null) {
            unset($data[SettingsNarrativePromptResolver::SETTING_KEY]);
        } else {
            $data[SettingsNarrativePromptResolver::SETTING_KEY] = $prompt;
        }

        $setting->data = $data;
        $setting->save();
    }

    private function resolveAccountId(): ?int
    {
        return $this->actingAccountId();
    }
}
