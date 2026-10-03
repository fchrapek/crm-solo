<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Models\Account;
use App\Services\Integrations\KiwwwiLeadsPullFailed;
use App\Services\Integrations\KiwwwiLeadsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pull FluentForm submissions from every configured brand site into the
 * kiwwwi lead pipeline via the WP mu-plugin endpoint (see KiwwwiLeadsService
 * for the transport contract and the per-site ref namespaces).
 *
 * "Since last sync" is the service's per-site cursor: the newest WP timestamp
 * of a fully staged batch from that site. The endpoint's `since` is
 * inclusive, so the boundary row is re-fetched and dedupe skips it.
 * Submissions are staged before any import (staged_lead_submissions), so a
 * failed or killed run loses nothing. One site failing never blocks another;
 * the run then exits non-zero.
 */
#[AccountScope(AccountScope::OPERATOR)]
final class SyncKiwwwiLeads extends Command
{
    private const LOCK_SECONDS = 600;

    protected $signature = 'kiwwwi:sync-leads
                            {--account= : The account ID to sync into (defaults to the only account)}
                            {--since= : Override the pull window start for every site (ISO 8601 / YYYY-MM-DD)}
                            {--full : Ignore previous pulls and fetch everything}
                            {--retry-failed : Put submissions that kept failing back in the retry queue}';

    protected $description = 'Pull FluentForm lead submissions from the kiwwwi brand sites into the kiwwwi pipeline (operator: the account named by --account, or the only one)';

    public function handle(): int
    {
        $account = $this->resolveAccount();
        if ($account === null) {
            return self::FAILURE;
        }

        // The scheduler's overlap guard does not cover a manual run, this lock does.
        $lock = Cache::lock('kiwwwi:sync-leads:'.$account->id, self::LOCK_SECONDS);
        if (! $lock->get()) {
            $this->warn('Another kiwwwi:sync-leads run for this account is in progress; nothing done.');

            return self::SUCCESS;
        }

        try {
            return $this->syncAccount($account);
        } finally {
            $lock->release();
        }
    }

    private function syncAccount(Account $account): int
    {
        $service = new KiwwwiLeadsService($account);

        try {
            $since = $this->parseSinceOption();
        } catch (Throwable $e) {
            return $this->failWithReason('Invalid --since value: '.$e->getMessage(), ['account_id' => $account->id]);
        }

        if ($this->option('retry-failed')) {
            $this->info(sprintf('Re-queued %d given-up submission(s).', $service->resetGivenUp()));
        }

        $this->info(sprintf('Pulling kiwwwi lead submissions for account %d…', $account->id));

        try {
            $stats = $service->sync($since, (bool) $this->option('full'));
        } catch (Throwable $e) {
            return $this->failWithReason('Kiwwwi lead sync failed: '.KiwwwiLeadsService::describe($e), [
                'account_id' => $account->id,
                'exception' => $e::class,
            ]);
        }

        $this->table(
            ['Site', 'Since', 'Fetched', 'Staged', 'Skipped', 'Malformed', 'Status'],
            collect($stats['endpoints'])->map(fn (array $row, string $key): array => [
                $key,
                $row['since'] ?? 'full history',
                $row['fetched'],
                $row['staged'],
                $row['skipped'],
                $row['malformed'],
                $row['error'] === null ? 'ok' : 'FAILED',
            ])->values()->all()
        );

        $this->table(
            ['Metric', 'Count'],
            [
                ['Fetched', $stats['fetched']],
                ['Staged', $stats['staged']],
                ['Created', $stats['created']],
                ['Skipped (already synced)', $stats['skipped']],
                ['Malformed (no id)', $stats['malformed']],
                ['Failed (retried next run)', $stats['failed']],
                ['Given up', $stats['given_up']],
            ]
        );

        if ($stats['given_up'] > 0) {
            $this->warn('Submissions no longer retried (fix the cause, then run with --retry-failed):');
            foreach ($service->givenUpSubmissions() as $failure) {
                $this->line(sprintf('  %s after %d attempts: %s', $failure->external_ref, $failure->attempts, $failure->error));
            }
        }

        $result = self::SUCCESS;
        foreach ($stats['endpoints'] as $key => $row) {
            $e = $row['error'];
            if ($e === null) {
                continue;
            }

            $result = $e instanceof KiwwwiLeadsPullFailed
                ? $this->failWithReason("Kiwwwi lead sync failed [{$key}]: ".$e->getMessage(), ['account_id' => $account->id, 'site_key' => $key, ...$e->context()])
                : $this->failWithReason("Kiwwwi lead sync failed [{$key}]: ".KiwwwiLeadsService::describe($e), ['account_id' => $account->id, 'site_key' => $key, 'exception' => $e::class]);
        }

        return $result;
    }

    private function resolveAccount(): ?Account
    {
        $accountId = $this->option('account');

        if ($accountId !== null) {
            $account = Account::find((int) $accountId);
            if ($account === null) {
                $this->failWithReason("Account [{$accountId}] not found.");
            }

            return $account;
        }

        if (Account::count() > 1) {
            $this->failWithReason('Multiple accounts exist — pass --account=.');

            return null;
        }

        $account = Account::first();
        if ($account === null) {
            $this->failWithReason('No account exists yet.');
        }

        return $account;
    }

    /**
     * The scheduler records only the exit code, so every failure goes to the
     * log as well as the console.
     *
     * @param  array<string, mixed>  $context
     */
    private function failWithReason(string $message, array $context = []): int
    {
        Log::error('kiwwwi:sync-leads: '.$message, $context);
        $this->error($message);

        return self::FAILURE;
    }

    private function parseSinceOption(): ?CarbonImmutable
    {
        $since = $this->option('since');

        return is_string($since) && $since !== '' ? CarbonImmutable::parse($since) : null;
    }
}
