<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Collection;

final class LeadCollection extends ResourceCollection
{
    public function toArray(Request $request): Collection
    {
        return $this->collection->map(fn ($lead) => [
            ...$lead->only('id', 'pipeline', 'name', 'company', 'email', 'phone', 'source', 'stage', 'client_id', 'deleted_at'),
            'captured_at' => $lead->captured_at?->toIso8601String(),
            'client_name' => $lead->client?->name,
            // Derived per row — the board shows tier because tier is what says
            // who gets Filip-minutes today.
            'score_total' => $lead->scoreTotal(),
            'tier' => $lead->tier(),
        ]);
    }
}
