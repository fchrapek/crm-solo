<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

final class User extends Authenticatable
{
    use Concerns\Filterable, HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /** The shared login DemoSeeder creates on the public demo. */
    public const DEMO_EMAIL = 'demo@crm-solo.test';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'password',
        'photo',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->where($field ?? 'id', $value)
            ->where('account_id', auth()->user()->account_id)
            ->withTrashed()
            ->firstOrFail();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function name(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->first_name.' '.$this->last_name,
        );
    }

    /** The demo login every visitor shares; only meaningful under DEMO_MODE. */
    public function isDemoUser(): bool
    {
        return (bool) config('app.demo') && $this->email === self::DEMO_EMAIL;
    }

    #[Scope]
    public function orderByName(Builder $query): void
    {
        $query
            ->orderBy('last_name')
            ->orderBy('first_name');
    }

    #[Scope]
    public function whereRole(Builder $query, string $role): void
    {
        $query->where('owner', match ($role) {
            'user' => false,
            'owner' => true,
        });
    }

    #[Scope]
    public function filter(Builder $query, array $filters, int $accountId): void
    {
        $query
            ->when($filters['search'] ?? null, fn ($query, $search) => $this->applySearchFilter($query, $search, $accountId, $filters['trashed'] ?? null))
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->whereRole($role))
            ->when($filters['trashed'] ?? null, fn ($query, $trashed) => $this->applyTrashedFilter($query, $trashed));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return array<int, string> */
    protected function searchableLikeColumns(): array
    {
        return ['first_name', 'last_name', 'email'];
    }
}
