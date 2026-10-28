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
            ->when($filters['search'] ?? null, fn ($query, $search) => $this->matchEveryWord($query, $search))
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

    /** "ewa dąbek" or "dąbek meble": each word must match the contact or its client's name. */
    private function matchEveryWord(Builder $query, string $search): void
    {
        foreach (preg_split('/\s+/u', mb_trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $like = '%'.$word.'%';
            $query->where(function (Builder $inner) use ($like): void {
                foreach ($this->searchableLikeColumns() as $column) {
                    $inner->orWhere($column, 'like', $like);
                }
                $inner->orWhereHas('client', fn (Builder $client) => $client->where('name', 'like', $like));
            });
        }
    }
}
