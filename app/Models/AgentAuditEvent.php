<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One record written by an agent verb or tool. Append-only: the app never
 * updates or deletes a row. `changes.before` holds the previous values of an
 * update, which is what a per-session undo needs.
 *
 * @property int $id
 * @property int $account_id
 * @property int|null $user_id
 * @property int|null $token_id
 * @property string $actor
 * @property string $via
 * @property string|null $session_id
 * @property string $verb
 * @property string $action
 * @property string $target_type
 * @property int $target_id
 * @property array<string, mixed>|null $changes
 */
final class AgentAuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'account_id',
        'user_id',
        'token_id',
        'actor',
        'via',
        'session_id',
        'verb',
        'action',
        'target_type',
        'target_id',
        'changes',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(AgentToken::class, 'token_id');
    }

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
