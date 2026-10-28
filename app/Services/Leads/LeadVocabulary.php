<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\Setting;

/**
 * The lead vocabulary one account sees: the shipped config/leadgen.php plus
 * that account's 'leadgen' setting (custom sources, source and pipeline labels).
 */
final class LeadVocabulary
{
    /** @return list<string> */
    public static function sources(int $accountId): array
    {
        return array_values(array_unique([
            ...(array) config('leadgen.sources', []),
            ...array_filter((array) (self::stored($accountId)['custom_sources'] ?? []), 'is_string'),
        ]));
    }

    /** @return array<string, string> */
    public static function sourceLabels(int $accountId): array
    {
        return [
            ...(array) config('leadgen.source_labels', []),
            ...array_filter((array) (self::stored($accountId)['source_labels'] ?? []), 'is_string'),
        ];
    }

    public static function pipelineLabel(int $accountId, string $pipeline): ?string
    {
        $label = self::stored($accountId)['pipeline_labels'][$pipeline] ?? null;

        return is_string($label) && $label !== '' ? $label : config("leadgen.pipelines.{$pipeline}.label");
    }

    /** @return array<string, mixed> */
    public static function stored(int $accountId): array
    {
        $data = Setting::query()->where('account_id', $accountId)->where('scope', 'leadgen')->value('data');

        return is_array($data) ? $data : [];
    }
}
