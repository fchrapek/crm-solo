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

final class Contact extends Model
{
    use Concerns\Filterable, HasFactory, SoftDeletes;

    protected $fillable = [
        'account_id',
        'client_id',
        'first_name',
        'last_name',
        'emails',
        'phone',
        'address',
        'city',
        'region',
        'country',
        'postal_code',
        'position',
        'phone_secondary',
        'notes',
    ];

    /**
     * Conventional "primary" email — the first entry in the emails array.
     * UI typically shows this; matching/sync use the full list.
     */
    public function primaryEmail(): ?string
    {
        $emails = $this->emails ?? [];

        return $emails[0] ?? null;
    }

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

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function name(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->first_name.' '.$this->last_name,
        );
    }

    #[Scope]
    public function orderByName(Builder $query): void
    {
        $query->orderBy('last_name')->orderBy('first_name');
    }

    #[Scope]
    public function filter(Builder $query, array $filters, int $accountId): void
    {
        $query
            ->when($filters['search'] ?? null, fn ($query, $search) => $this->applySearchFilter($query, $search, $accountId, $filters['trashed'] ?? null))
            ->when($filters['trashed'] ?? null, fn ($query, $trashed) => $this->applyTrashedFilter($query, $trashed));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'emails' => 'array',
        ];
    }

    /** @return array<int, string> */
    protected function searchableLikeColumns(): array
    {
        return ['first_name', 'last_name', 'city', 'phone', 'emails'];
    }
}
