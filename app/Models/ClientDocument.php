<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Client-scoped documents (signed contracts, GDPR clauses, sub-processing
 * agreements). Files live LOCALLY under
 * storage/app/private/client-documents/{client_id}/. Account scoping is
 * enforced via the parent client.
 */
final class ClientDocument extends Model
{
    protected $fillable = [
        'account_id',
        'client_id',
        'file_path',
        'original_name',
        'mime',
        'size',
        'category',
        'label',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }
}
