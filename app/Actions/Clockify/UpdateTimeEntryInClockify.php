<?php

declare(strict_types=1);

namespace App\Actions\Clockify;

use App\Models\Integration;
use App\Models\TimeEntry;
use App\Services\Integrations\Clockify\ClockifyMirrorService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Best-effort push of an edited TimeEntry to Clockify. Mirror of
 * PushTimeEntryToClockify but for entries already mirrored — fires PUT on the
 * existing Clockify ID instead of POST on the collection. Used by the time-
 * entry edit dialog so corrections (e.g. overnight session trimmed to real
 * worked time) propagate upstream.
 *
 * Failures log and swallow. A Clockify outage never blocks a local edit; the
 * row stays as-is locally and the user can retry from the integrations panel.
 */
final class UpdateTimeEntryInClockify
{
    public function __invoke(TimeEntry $entry): bool
    {
        if ($entry->clockify_entry_id === null || $entry->clockify_entry_id === '') {
            return false;
        }

        $integration = Integration::query()
            ->where('account_id', $entry->account_id)
            ->where('provider', 'clockify')
            ->where('is_enabled', true)
            ->first();

        if ($integration === null || ! $integration->isConfigured()) {
            return false;
        }

        try {
            return (new ClockifyMirrorService($integration))->updateTimeEntry($entry);
        } catch (Throwable $e) {
            Log::warning('Failed to update TimeEntry in Clockify', [
                'time_entry_id' => $entry->id,
                'clockify_entry_id' => $entry->clockify_entry_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
