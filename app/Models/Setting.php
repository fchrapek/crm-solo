<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-account settings document, one row per scope ('leadgen', …).
 * Shipped defaults live in config/*.php; this stores only what the user
 * customized. Merging happens in AppServiceProvider::boot().
 */
final class Setting extends Model
{
    protected $fillable = [
        'account_id',
        'scope',
        'data',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    protected function casts(): array
    {
        return [
            // Without this cast, saving arrays throws "Array to string
            // conversion" (see CLAUDE.md pitfalls).
            'data' => 'array',
        ];
    }
}
