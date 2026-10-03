<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A day ended on Gotowe with no timer running. This row is the day's stamp
 * in the month card; starting a timer on the same day deletes it.
 */
final class DayClose extends Model
{
    protected $fillable = ['account_id', 'date', 'closed_at'];

    protected function casts(): array
    {
        return ['date' => 'date', 'closed_at' => 'datetime'];
    }
}
