<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Models\AgentToken;
use Illuminate\Console\Command;

/** Lists agent tokens without ever printing a token or its hash. */
#[AccountScope(AccountScope::OPERATOR)]
final class AgentTokenList extends Command
{
    protected $signature = 'agent-tokens:list {--account= : Only this account id} {--all : Include revoked and expired tokens} {--json : Machine-readable output}';

    protected $description = 'List agent tokens: name, user, abilities, last use, expiry (operator: every account, or the one named by --account)';

    public function handle(): int
    {
        $tokens = AgentToken::query()
            ->with('tokenable')
            ->when($this->option('account') !== null, fn ($q) => $q->where('account_id', (int) $this->option('account')))
            ->orderBy('id')
            ->get()
            ->filter(fn (AgentToken $t): bool => (bool) $this->option('all') || (! $t->isRevoked() && ! $t->isExpired()));

        $rows = $tokens->map(fn (AgentToken $t): array => [
            'id' => $t->id,
            'name' => $t->name,
            'account_id' => $t->account_id,
            'user' => $t->tokenable?->email,
            'abilities' => (array) $t->abilities,
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'expires_at' => $t->expires_at?->toIso8601String(),
            'state' => $t->isRevoked() ? 'revoked' : ($t->isExpired() ? 'expired' : 'active'),
        ])->values();

        if ($this->option('json')) {
            $this->line((string) json_encode($rows->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($rows->isEmpty()) {
            $this->line('No agent tokens.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'name', 'account', 'user', 'abilities', 'last used', 'expires', 'state'],
            $rows->map(fn (array $r): array => [
                $r['id'], $r['name'], $r['account_id'], $r['user'], implode(' ', $r['abilities']),
                $r['last_used_at'] ?? 'never', $r['expires_at'] ?? 'never', $r['state'],
            ])->all(),
        );

        return self::SUCCESS;
    }
}
