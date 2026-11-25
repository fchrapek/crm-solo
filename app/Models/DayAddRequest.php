<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** One submit of the day's add field: the client's request id, the payload it carried and the task it made. */
final class DayAddRequest extends Model
{
    use MassPrunable;

    /** A retry comes within seconds; a week covers a laptop that slept on the request. */
    public const int KEEP_DAYS = 7;

    protected $fillable = ['account_id', 'request_id', 'fingerprint', 'task_id'];

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
