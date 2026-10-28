<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\AgentToken;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issuing, re-scoping and revoking agent tokens, for the artisan commands and
 * the Agenci settings tab alike. Only the SHA-256 hash is stored; the plain
 * text exists once, in the NewAccessToken that issue() returns.
 */
final class AgentTokens
{
    /**
     * @param  list<string>  $abilities  as requested; `write` expands, read is required
     *
     * @throws InvalidArgumentException
     */
    public function issue(User $user, string $name, array $abilities, ?CarbonInterface $expiresAt): NewAccessToken
    {
        if ($user->account_id === null) {
            throw new InvalidArgumentException('The user has no account.');
        }

        $name = mb_trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Give the token a name of 1 to 120 characters.');
        }

        $abilities = $this->checked($abilities);

        return DB::transaction(function () use ($user, $name, $abilities, $expiresAt): NewAccessToken {
            $issued = $user->createToken($name, $abilities, $expiresAt);
            $issued->accessToken->forceFill(['account_id' => $user->account_id])->save();

            return $issued;
        });
    }

    /**
     * Replaces the token's abilities. Returns null when the token is revoked,
     * since a revoked token never comes back.
     *
     * @param  list<string>  $abilities
     *
     * @throws InvalidArgumentException
     */
    public function setAbilities(AgentToken $token, array $abilities): ?AgentToken
    {
        $abilities = $this->checked($abilities);

        return $this->rescope($token, fn (): array => $abilities);
    }

    /**
     * Adds abilities to what the token holds, read under the row lock so two grants never drop each other.
     *
     * @param  list<string>  $adding
     *
     * @throws InvalidArgumentException
     */
    public function grant(AgentToken $token, array $adding): ?AgentToken
    {
        $adding = AgentAbilities::normalise($adding);

        return $this->rescope($token, fn (AgentToken $locked): array => $this->checked([...(array) $locked->abilities, ...$adding]));
    }

    /** Stamps revoked_at; the row stays so audit rows keep its name. False when it already was revoked. */
    public function revoke(AgentToken $token): bool
    {
        if ($token->isRevoked()) {
            return false;
        }

        $token->forceFill(['revoked_at' => now()])->save();

        return true;
    }

    /** @param  callable(AgentToken): list<string>  $abilities */
    private function rescope(AgentToken $token, callable $abilities): ?AgentToken
    {
        return DB::transaction(function () use ($token, $abilities): ?AgentToken {
            $locked = AgentToken::query()->lockForUpdate()->find($token->id);
            if ($locked === null || $locked->isRevoked()) {
                return null;
            }
            $locked->forceFill(['abilities' => $abilities($locked)])->save();

            return $locked;
        });
    }

    /**
     * @param  list<string>  $abilities
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private function checked(array $abilities): array
    {
        $abilities = AgentAbilities::normalise($abilities);
        if (! in_array(AgentAbilities::READ, $abilities, true)) {
            throw new InvalidArgumentException('Every token needs read: each verb and tool reads before it writes.');
        }

        return $abilities;
    }
}
