<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'max:25'],
            'last_name' => ['required', 'max:25'],
            'client_id' => [
                'nullable',
                Rule::exists('clients', 'id')->where(fn ($query) => $query->where('account_id', Auth::user()->account_id)),
            ],
            'emails' => ['nullable', 'array', 'max:10'],
            'emails.*' => ['required', 'string', 'email', 'max:255', 'distinct'],
            'phone' => ['nullable', 'max:50'],
            'address' => ['nullable', 'max:150'],
            'city' => ['nullable', 'max:50'],
            'region' => ['nullable', 'max:50'],
            'country' => ['nullable', 'max:2'],
            'postal_code' => ['nullable', 'max:25'],
            'position' => ['nullable', 'max:100'],
            'phone_secondary' => ['nullable', 'max:50'],
            'notes' => ['nullable'],
        ];
    }
}
