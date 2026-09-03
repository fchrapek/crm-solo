<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Lead;
use App\Services\Integrations\KiwwwiLeadsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pull kiwwwi.pl FluentForm submissions into the kiwwwi lead pipeline via the
 * WP mu-plugin endpoint (see KiwwwiLeadsService for the transport contract).
 *
 * "Since last sync" is stateless: the newest captured_at among already-pulled
 * kiwwwi leads (external_ref set, trashed included) is the resume point. The
 * endpoint's `since` is inclusive and compares WP's own timestamps, so the
 * boundary row is re-fetched and dedupe skips it — no sync-state row, no
 * clock-skew maths.
 */
final class SyncKiwwwiLeads extends Command
{
    protected $signature = 'kiwwwi:sync-leads
                            {--account= : The account ID to sync into (defaults to the only account)}
                            {--since= : Override the pull window start (ISO 8601 / YYYY-MM-DD)}
                            {--full : Ignore previous pulls and fetch everything}';

    protected $description = 'Pull kiwwwi.pl FluentForm lead submissions into the kiwwwi pipeline';

    public function handle(): int
    {
        $account = $this->resolveAccount();
        if ($account === null) {
            return self::FAILURE;
        }

        try {
            $since = $this->resolveSince($account);
        } catch (Throwable $e) {
            $this->error('Invalid --since value: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Pulling kiwwwi lead submissions for account %d%s…',
            $account->id,
            $since !== null ? " since {$since->toIso8601String()}" : ' (full history)'
        ));

        try {
            $stats = (new KiwwwiLeadsService($account))->sync($since);
        } catch (Throwable $e) {
            $this->error('Kiwwwi lead sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Fetched', $stats['fetched']],
                ['Created', $stats['created']],
                ['Skipped (already synced)', $stats['skipped']],
                ['Malformed', $stats['malformed']],
            ]
        );

        return self::SUCCESS;
    }

    private function resolveAccount(): ?Account
    {
        $accountId = $this->option('account');

        if ($accountId !== null) {
            $account = Account::find((int) $accountId);
            if ($account === null) {
                $this->error("Account [{$accountId}] not found.");
            }

            return $account;
        }

        if (Account::count() > 1) {
            $this->error('Multiple accounts exist — pass --account=.');

            return null;
        }

        $account = Account::first();
        if ($account === null) {
            $this->error('No account exists yet.');
        }

        return $account;
    }

    private function resolveSince(Account $account): ?CarbonImmutable
    {
        if (is_string($this->option('since')) && $this->option('since') !== '') {
            return CarbonImmutable::parse($this->option('since'));
        }

        if ($this->option('full')) {
            return null;
        }

        $lastCapturedAt = Lead::withTrashed()
            ->where('account_id', $account->id)
            ->where('pipeline', 'kiwwwi')
            ->whereNotNull('external_ref')
            ->max('captured_at');

        return $lastCapturedAt === null ? null : CarbonImmutable::parse($lastCapturedAt);
    }
}
