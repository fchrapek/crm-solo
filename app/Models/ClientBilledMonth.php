<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\HumanizedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A client's billed figure for one month set by hand, overriding the rounded real time; the note is for the owner only. */
final class ClientBilledMonth extends Model
{
    protected $fillable = [
        'client_id',
        'period',
        'billed_minutes',
        'note',
        'updated_by_user_id',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'billed_minutes' => 'integer',
            'note' => HumanizedText::class,
        ];
    }
}
