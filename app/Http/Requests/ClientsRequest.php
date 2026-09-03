<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ClientsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:business,individual'],
            'name' => ['required', 'max:100'],
            'email' => ['nullable', 'max:191', 'email'],
            'phone' => ['nullable', 'max:50'],
            'address' => ['nullable', 'max:150'],
            'city' => ['nullable', 'max:50'],
            'region' => ['nullable', 'max:50'],
            'country' => ['nullable', 'max:2'],
            'postal_code' => ['nullable', 'max:25', 'post_code'],
            'tax_id' => ['nullable', 'max:20', 'NIP'],
            'business_type' => ['nullable', 'max:100'],
            'segment' => ['nullable', 'string', 'in:'.implode(',', \App\Models\Client::SEGMENTS)],
            'cooperation_type' => ['nullable', 'string', 'in:'.implode(',', \App\Models\Client::COOPERATION_TYPES)],
            'currency' => ['nullable', 'string', 'size:3', 'in:PLN,EUR,USD,GBP'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'include_in_month_close' => ['boolean'],
            'ssh_config' => ['nullable', 'string', 'max:1024'],
            'maintenance_invoice_description' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable'],
        ];
    }
}
