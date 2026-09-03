<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Setting;
use Illuminate\Database\QueryException;

/**
 * Resolves the report prompt from the account's settings row, falling back to
 * the prompt shipped in `resources/prompts/report-narrative.md`.
 *
 * Same seam as the leadgen vocabulary (AppServiceProvider::mergeLeadgenSettings):
 * defaults live in the repository, customization lives in the settings table.
 * That keeps an instance's own wording in its database rather than in a file
 * path an env var points at, so there is nothing to mount on a hosted
 * instance and nothing to bake into `config:cache`.
 *
 * Import an override with `php artisan reports:prompt-import <path>`.
 */
final class SettingsNarrativePromptResolver implements NarrativePromptResolver
{
    public const SETTING_SCOPE = 'reports';

    public const SETTING_KEY = 'narrative_prompt';

    public function resolve(int $accountId): string
    {
        $override = $this->override($accountId);

        if ($override !== null) {
            return $override;
        }

        $shipped = $this->shipped();

        if ($shipped !== null) {
            return $shipped;
        }

        throw NarrativePromptUnavailable::forPath($this->path());
    }

    public function source(int $accountId): ?string
    {
        if ($this->override($accountId) !== null) {
            return 'settings';
        }

        return $this->shipped() !== null ? 'file' : null;
    }

    public function path(): string
    {
        return resource_path('prompts/report-narrative.md');
    }

    /**
     * Guarded like the leadgen merge: a missing settings table on a
     * mid-migrate install degrades to the shipped prompt instead of dying.
     * Only a query failure, so a bug in this method still surfaces rather
     * than silently swapping the instance's prompt for the shipped one.
     */
    private function override(int $accountId): ?string
    {
        try {
            $data = Setting::query()
                ->where('account_id', $accountId)
                ->where('scope', self::SETTING_SCOPE)
                ->value('data');
        } catch (QueryException) {
            return null;
        }

        if (! is_array($data)) {
            return null;
        }

        $prompt = $data[self::SETTING_KEY] ?? null;

        return is_string($prompt) && mb_trim($prompt) !== '' ? $prompt : null;
    }

    private function shipped(): ?string
    {
        $path = $this->path();

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return is_string($contents) && mb_trim($contents) !== '' ? $contents : null;
    }
}
