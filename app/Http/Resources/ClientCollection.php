<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Collection;

final class ClientCollection extends ResourceCollection
{
    public function toArray(Request $request): Collection
    {
        return $this->collection->map(fn ($client) => [
            ...$client->only('id', 'type', 'name', 'phone', 'city', 'is_pinned', 'lifecycle_stage', 'deleted_at'),
            'contacts_count' => $client->contacts_count ?? 0,
        ]);
    }
}
