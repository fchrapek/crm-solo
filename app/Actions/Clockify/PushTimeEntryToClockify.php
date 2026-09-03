<?php

declare(strict_types=1);

namespace App\Actions\Clockify;

use App\Models\Integration;
use App\Models\TimeEntry;
use App\Services\Integrations\Clockify\ClockifyMirrorService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Best-effort push of a closed TimeEntry to Clockify. Used from both the
 * terminal-session close hook and the manual time entry controller — single
 * call site keeps the "is this even pushable / do we have an integration"
 * logic out of those callers.
 *
 * Failures log and swallow. We never want a Clockify outage to block a user
 * ending their session or saving a manual entry; the row stays as-is with
 * `clockify_entry_id = null` and the backfill button can retry later.
 */
final class PushTimeEntryToClockify
{
    /**
     * @return ?string the Clockify time-entry id (existing or new), or null
     *                 if the push was skipped or failed.
     */
    public function __invoke(TimeEntry $entry): ?string
    {
        if ($entry->clockify_entry_id !== null && $entry->clockify_entry_id !== '') {
            return $entry->clockify_entry_id;
        }
        if ($entry->end_time === null) {
            return null;
        }

        $integration = Integration::query()
            ->where('account_id', $entry->account_id)
            ->where('provider', 'clockify')
            ->where('is_enabled', true)
            ->first();

        if ($integration === null || ! $integration->isConfigured()) {
            // Integration not set up — silently no-op. The local row still
            // captures the time; pushing is opt-in.
            return null;
        }

        try {
            return (new ClockifyMirrorService($integration))->pushTimeEntry($entry);
        } catch (Throwable $e) {
            Log::warning('Failed to push TimeEntry to Clockify', [
                'time_entry_id' => $entry->id,
                'task_id' => $entry->task_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
