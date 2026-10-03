<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Models\Lead;
use App\Services\Leads\ClickIdScrubber;
use App\Services\Leads\MarketingConsent;
use Illuminate\Console\Command;

/**
 * Backfill for the 2026-09-29 consent rule: kiwwwi leads imported before the
 * site recorded consent carry click ids in their notes with no proof the
 * visitor agreed. Their consent is unknown, and unknown is treated as not
 * granted, so the click ids go.
 *
 * Every kiwwwi lead is checked. A lead with marketing_consent = granted keeps
 * its Google click ids (gclid/gbraid/wbraid/dclid) and loses the rest
 * (fbclid, msclkid): the column records the Google Ads grant only. Detection
 * is "the scrubbed notes differ from the original", the same scrub import
 * uses, so encoded, upper-case, array-style and fragment keys are all caught.
 *
 * Dry run by default (lists what would change, never the id values);
 * --apply writes. Soft-deleted leads are included: retention covers them too.
 * `source` is never touched, so ads attribution stays. Idempotent: a second
 * run finds nothing. Scrubbed notes cannot be restored, so take a database
 * backup before --apply.
 */
#[AccountScope(AccountScope::OPERATOR)]
final class ScrubKiwwwiClickIds extends Command
{
    protected $signature = 'kiwwwi:scrub-click-ids
                            {--apply : Write the changes (default is a dry run)}
                            {--account= : Limit to one account id}';

    protected $description = 'Strip ad click ids from kiwwwi leads unless the issuing service was granted, dry run unless --apply (operator: every account, or the one named by --account)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $query = Lead::withTrashed()
            ->where('pipeline', 'kiwwwi')
            ->whereNotNull('notes')
            ->orderBy('id');

        if (is_numeric($this->option('account'))) {
            $query->where('account_id', (int) $this->option('account'));
        }

        $rows = [];
        $changed = 0;

        foreach ($query->cursor() as $lead) {
            $notes = (string) $lead->notes;
            $keep = MarketingConsent::keptClickIdsForColumn($lead->marketing_consent);
            $scrubbed = ClickIdScrubber::text($notes, $keep);
            if ($scrubbed === $notes) {
                continue;
            }

            $removed = array_values(array_diff(ClickIdScrubber::found($notes), $keep));
            $changed++;

            $rows[] = [
                $lead->id,
                $lead->external_ref ?? '-',
                $lead->source,
                $lead->marketing_consent ?? MarketingConsent::UNKNOWN,
                $lead->trashed() ? 'yes' : 'no',
                $removed === [] ? '(click id)' : implode(', ', $removed),
            ];

            if ($apply) {
                $lead->notes = mb_trim($scrubbed) === '' ? null : $scrubbed;
                // Not a user edit: keep updated_at as it was.
                Lead::withoutTimestamps(fn () => $lead->save());
            }
        }

        if ($rows === []) {
            $this->info('No kiwwwi lead holds a click id it may not keep. Nothing to do.');

            return self::SUCCESS;
        }

        $this->table(['Lead', 'Ref', 'Source', 'Consent', 'Trashed', 'Click ids'], $rows);

        $apply
            ? $this->info("Stripped click ids from {$changed} lead(s). Source attribution unchanged.")
            : $this->warn("Dry run: {$changed} lead(s) would change. Re-run with --apply to write.");

        return self::SUCCESS;
    }
}
