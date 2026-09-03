<?php

declare(strict_types=1);

namespace App\Casts;

use App\Services\Humanizer;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Content-field cast: every write path (UI form, crm verb, job, sync) runs
 * through the deterministic humanizer gate — no route can store em dashes,
 * curly quotes or invisible LLM artifacts.
 */
final class HumanizedText implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return is_string($value) ? Humanizer::clean($value) : $value;
    }
}
