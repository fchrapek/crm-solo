<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Index-page filtering. Search is a plain LIKE across the model's declared
 * columns; Typesense/Scout was retired in 2026-07 because at this data
 * scale (hundreds of rows) a
 * LIKE matches the search-box behavior without an external search engine,
 * and the account scope is enforced by the calling query.
 */
trait Filterable
{
    /**
     * Columns the search box matches on. Override per model.
     *
     * @return array<int, string>
     */
    protected function searchableLikeColumns(): array
    {
        return ['name'];
    }

    private function applySearchFilter(Builder $query, string $search, int $accountId, ?string $trashed): void
    {
        $like = '%'.$search.'%';

        $query->where(function (Builder $inner) use ($like): void {
            foreach ($this->searchableLikeColumns() as $column) {
                $inner->orWhere($column, 'like', $like);
            }
        });

        $this->applyTrashedFilter($query, (string) $trashed);
    }

    private function applyTrashedFilter(Builder $query, string $trashed): void
    {
        if ($trashed === 'with') {
            $query->withTrashed();
        } elseif ($trashed === 'only') {
            $query->onlyTrashed();
        }
    }
}
