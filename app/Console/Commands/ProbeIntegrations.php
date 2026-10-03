<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Models\Account;
use App\Models\Integration;
use App\Services\Integrations\InfaktService;
use App\Services\Integrations\KiwwwiLeadsService;
use App\Services\Integrations\Trello\TrelloService;
use App\Support\Redaction;
use Illuminate\Console\Command;
use Throwable;

/**
 * Checks that this server can read and use each integration's credentials,
 * with GET requests only and no database write, not even a sync error: the
 * smoke after a deploy and the first look from a new server.
 */
#[AccountScope(AccountScope::OPERATOR)]
final class ProbeIntegrations extends Command
{
    protected $signature = 'integrations:probe
                            {--account= : One account ID (default: every account with an enabled integration)}
                            {--json : Print the results as JSON}';

    protected $description = 'Read-only check of the Trello, Infakt and kiwwwi connections, GET only, writes nothing (operator: every account with an integration, or the one named by --account)';

    /** @var list<array{account: ?int, system: string, check: string, result: string, detail: string}> */
    private array $results = [];

    /** @var list<string> */
    private array $secrets = [];

    public function handle(): int
    {
        $accountId = $this->selectedAccount();
        if ($accountId === false) {
            return self::FAILURE;
        }

        $integrations = Integration::query()
            ->where('is_enabled', true)
            ->when($accountId !== null, fn ($q) => $q->where('account_id', $accountId))
            ->orderBy('account_id')
            ->get();

        foreach ($integrations as $integration) {
            $this->probe($integration);
        }

        $this->probeKiwwwi($accountId ?? Account::query()->orderBy('id')->value('id'));

        if ($this->option('json')) {
            $this->line((string) json_encode($this->results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Account', 'System', 'Check', 'Result', 'Detail'], $this->results);
        }

        $results = collect($this->results)->pluck('result');
        if ($results->contains('fail')) {
            return self::FAILURE;
        }
        if (! $results->contains('ok')) {
            $this->error('Nothing was checked: no enabled integration and no lead endpoint configured.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** null for every account, false when --account names no existing account. */
    private function selectedAccount(): int|false|null
    {
        if (! $this->input->hasParameterOption('--account')) {
            return null;
        }
        $raw = (string) $this->option('account');

        if (! ctype_digit((string) $raw) || (int) $raw < 1 || ! Account::query()->whereKey((int) $raw)->exists()) {
            $this->error("--account must be the id of an existing account, got '{$raw}'.");

            return false;
        }

        return (int) $raw;
    }

    private function probe(Integration $integration): void
    {
        $account = (int) $integration->account_id;
        $system = (string) $integration->provider;

        try {
            $unreadable = $integration->unreadableSecrets();
            if ($unreadable !== []) {
                $this->record($account, $system, 'credentials decrypt', 'fail', 'unreadable: '.implode(', ', $unreadable).' (wrong APP_KEY?)');

                return;
            }
            $this->secrets = array_values(array_filter([
                (string) $integration->api_key,
                (string) ($integration->settings['trello_api_key'] ?? ''),
            ]));
            $this->record($account, $system, 'credentials decrypt', 'ok', '');

            match ($system) {
                'trello' => $this->probeTrello($account, $integration),
                'infakt' => $this->probeInfakt($account, $integration),
                default => $this->record($account, $system, 'connection', 'skipped', 'no probe for this provider'),
            };
        } catch (Throwable $e) {
            $this->record($account, $system, 'probe', 'fail', $e::class.': '.$e->getMessage());
        } finally {
            $this->secrets = [];
        }
    }

    private function probeTrello(int $account, Integration $integration): void
    {
        $trello = new TrelloService($integration);
        if (! $trello->testConnection()) {
            $this->record($account, 'trello', 'connection', 'fail', 'members/me refused');

            return;
        }
        $boards = $trello->fetchBoards();
        $detail = count($boards).' open boards';
        if ($boards !== []) {
            $detail .= ', '.count($trello->fetchLists((string) $boards[0]['id'])).' lists on the first';
        }
        $this->record($account, 'trello', 'connection', 'ok', $detail);
    }

    private function probeInfakt(int $account, Integration $integration): void
    {
        $ok = (new InfaktService($integration))->testConnection();
        $this->record($account, 'infakt', 'connection', $ok ? 'ok' : 'fail', $ok ? 'clients.json answered' : 'clients.json refused (details in the log)');
    }

    /**
     * The lead endpoints are configured for the whole server, not per account;
     * the account only satisfies the service and labels the row.
     */
    private function probeKiwwwi(?int $accountId): void
    {
        if (blank(config('services.kiwwwi.leads.app_password'))) {
            $this->record($accountId, 'kiwwwi', 'connection', 'skipped', 'not configured');

            return;
        }

        $account = $accountId === null ? null : Account::query()->find($accountId);
        if ($account === null) {
            $this->record($accountId, 'kiwwwi', 'connection', 'skipped', 'no account to run under');

            return;
        }

        $this->secrets = [(string) config('services.kiwwwi.leads.app_password')];
        try {
            foreach ((new KiwwwiLeadsService($account))->probe(now()->subDay()) as $endpoint => $row) {
                $this->record($accountId, 'kiwwwi', "endpoint {$endpoint}", $row['error'] === null ? 'ok' : 'fail', $row['error'] ?? "{$row['fetched']} submissions in the last day");
            }
        } catch (Throwable $e) {
            $this->record($accountId, 'kiwwwi', 'connection', 'fail', KiwwwiLeadsService::describe($e, $this->secrets));
        } finally {
            $this->secrets = [];
        }
    }

    private function record(?int $account, string $system, string $check, string $result, string $detail): void
    {
        $detail = str_replace(Redaction::variants($this->secrets), '[redacted]', $detail);
        $this->results[] = compact('account', 'system', 'check', 'result', 'detail');
    }
}
