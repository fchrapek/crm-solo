<?php

declare(strict_types=1);

namespace App\Casts;

use App\Models\Task;
use App\Services\Humanizer;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A task description is CRM prose on a manual task, humanized on write like
 * every other content field, and a client's own text on a Trello card,
 * stored exactly as the card holds it. The sync is the only writer of a
 * card's description (the task page refuses to edit one), so the card check
 * at the write is the sync's path.
 */
final class TaskDescription implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if (! is_string($value) || ($model instanceof Task && $model->hasTrelloCard())) {
            return $value;
        }

        return Humanizer::clean($value);
    }
}
