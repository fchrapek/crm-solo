<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Client
 */
final class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'region' => $this->region,
            'country' => $this->country,
            'postal_code' => $this->postal_code,
            'tax_id' => $this->tax_id,
            'business_type' => $this->business_type,
            'notes' => $this->notes,
            'is_pinned' => $this->is_pinned,
            'month_close_type' => $this->month_close_type,
            'include_in_month_close' => (bool) $this->include_in_month_close,
            'report_mode' => $this->report_mode,
            'ssh_config' => $this->ssh_config,
            'maintenance_invoice_description' => $this->maintenance_invoice_description,
            'lifecycle_stage' => $this->lifecycle_stage,
            'segment' => $this->segment,
            'cooperation_type' => $this->cooperation_type,
            'currency' => $this->currency,
            'hourly_rate' => $this->hourly_rate !== null ? (float) $this->hourly_rate : null,
            'deleted_at' => $this->deleted_at,
            'contacts' => $this->contacts()->orderByName()->get()->map->only('id', 'name', 'city', 'phone'),
        ];
    }
}
