<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'owner' => $this->owner,
            'deleted_at' => $this->deleted_at,
            'account' => $this->whenLoaded('account'),
            'can_delete' => ! $this->isDemoUser() && (bool) $request->user()?->can('delete', $this->resource),
        ];
    }
}
