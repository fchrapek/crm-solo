<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Agent\AgentAbilities;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A personal access token an agent uses to reach a hosted CRM. Bound to a
 * user and to that user's account; stored as a SHA-256 hash, the plain text
 * is shown once at issue. Revoking stamps `revoked_at` instead of deleting,
 * so audit rows keep pointing at a named token.
 *
 * @property int $id
 * @property int|null $account_id
 * @property string $name
 * @property list<string>|null $abilities
 */
final class AgentToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'account_id',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Whether this token grants $ability (stored abilities are explicit; `write` was expanded at issue). */
    public function allows(string $ability): bool
    {
        return AgentAbilities::grants((array) $this->abilities, $ability);
    }

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'abilities' => 'json',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
