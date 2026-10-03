<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A pulled submission waiting to become a lead. Imported (and deleted) on the
 * run that stages it or a later one; after MAX_ATTEMPTS failed imports it is
 * given up and only listed, until `--retry-failed` puts it back.
 */
final class StagedLeadSubmission extends Model
{
    public const int MAX_ATTEMPTS = 5;

    protected $fillable = [
        'account_id',
        'external_ref',
        'payload',
        'error',
        'attempts',
        'given_up_at',
    ];

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('given_up_at');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeGivenUp(Builder $query): void
    {
        $query->whereNotNull('given_up_at');
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'given_up_at' => 'datetime',
        ];
    }
}
