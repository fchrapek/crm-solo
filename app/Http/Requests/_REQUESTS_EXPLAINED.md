# Requests (Form Validation) - Explained Like I'm 5

## What is a Request?

**ELI5:** A Request is like a bouncer at a club.

```
Form Data (guest)      Bouncer (Request)        Controller (club)
        │                      │                         │
        │  "Let me in"         │                         │
        ├─────────────────────►│                         │
        │                      │                         │
        │      ┌───────────────┴───────────────┐         │
        │      │ Check ID (name required?)     │         │
        │      │ Check age (email valid?)      │         │
        │      │ Check dress code (max:100?)   │         │
        │      └───────────────┬───────────────┘         │
        │                      │                         │
        │                      │  ✅ "OK, come in"       │
        │                      ├────────────────────────►│
        │                      │                         │
        │  ❌ "No, go back"    │                         │
        │◄─────────────────────┤                         │
```

- Checks form data before it reaches the Controller
- If invalid → sends user back with error messages
- If valid → lets data through

---

## Basic Structure

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ClientsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'max:100'],
            'email' => ['nullable', 'email'],
        ];
    }
}
```

The `rules()` method returns an array:
- Key = field name
- Value = array of validation rules

---

## Common Validation Rules

| Rule | Meaning | Example |
|------|---------|---------|
| `'required'` | Must be provided | Can't be empty |
| `'nullable'` | Can be empty/null | Optional field |
| `'max:100'` | Max 100 characters | Length limit |
| `'min:3'` | Min 3 characters | Minimum length |
| `'email'` | Valid email format | `test@example.com` |
| `'url'` | Valid URL | `https://example.com` |
| `'numeric'` | Must be a number | `123` |
| `'integer'` | Must be an integer | `123` (not `12.3`) |
| `'boolean'` | Must be true/false | Checkboxes |
| `'date'` | Valid date | `2024-01-31` |
| `'in:a,b,c'` | Must be one of these | Dropdown options |
| `'unique:users,email'` | Not already in DB | Registration |
| `'exists:clients,id'` | Must exist in DB | Foreign key |
| `'confirmed'` | Must match `field_confirmation` | Password confirm |

---

## Polish-Specific Rules (pacerit package)

We installed `pacerit/laravel-polish-validation-rules` for these. The sample
values below deliberately fail their checksums, so they can never collide with
a real registration:

| Rule | Meaning | Example |
|------|---------|---------|
| `'NIP'` | Polish tax ID (10 digits + checksum) | `1234567890` |
| `'REGON'` | Polish statistical number | `123456780` |
| `'post_code'` | Polish postal code | `41-300` |
| `'id_card_number'` | Polish ID card | `ABC123456` |

---

## How to Use in Controller

```php
public function store(ClientsRequest $request): RedirectResponse
{
    // If we get here, validation already passed!
    
    $data = $request->validated();  // Only validated fields
    
    Auth::user()->account->clients()->create($data);
}
```

| Method | What it returns |
|--------|-----------------|
| `$request->all()` | Everything from form (unsafe!) |
| `$request->validated()` | Only validated fields (safe!) |
| `$request->input('name')` | Single field value |

---

## What Happens on Validation Failure?

Laravel automatically:
1. Redirects back to the form
2. Flashes error messages to session
3. Flashes old input (so form isn't empty)

In your React form, errors appear via Inertia's `usePage().props.errors`.

---

## Combining Multiple Rules

Rules are applied in order:

```php
'email' => ['required', 'email', 'max:50', 'unique:clients,email']
//          1. Must exist
//          2. Must be valid email format
//          3. Max 50 characters
//          4. Must not exist in clients.email column
```

---

## Conditional Rules

Sometimes rules depend on other fields:

```php
public function rules(): array
{
    $rules = [
        'type' => ['required', 'in:business,individual'],
        'name' => ['required', 'max:100'],
    ];
    
    // Only require tax_id for business clients
    if ($this->input('type') === 'business') {
        $rules['tax_id'] = ['required', 'NIP'];
    }
    
    return $rules;
}
```

---

## Custom Error Messages

```php
public function messages(): array
{
    return [
        'name.required' => 'Client name is required.',
        'email.email' => 'Please enter a valid email address.',
        'tax_id.NIP' => 'Invalid Polish tax ID (NIP).',
    ];
}
```

---

## Why Separate Request Class?

Same reason as filter in Model - **reusability!**

```php
// Used in store() for creating
public function store(ClientsRequest $request) { ... }

// Used in update() for editing - same rules!
public function update(Client $client, ClientsRequest $request) { ... }
```

One place to define rules. Used everywhere.

---

## The Flow

```
User submits form
       │
       ▼
Laravel sees ClientsRequest in controller signature
       │
       ▼
Laravel creates ClientsRequest instance
       │
       ▼
Laravel calls rules() and validates data
       │
       ├── ❌ Validation fails → Redirect back with errors
       │
       └── ✅ Validation passes → Continue to controller method
```

---

## Questions to Test Yourself

1. **What is the purpose of a Form Request?**

2. **What's the difference between `$request->all()` and `$request->validated()`?**

3. **What does the `'nullable'` rule do?**

4. **What happens automatically when validation fails?**

5. **Why put validation in a separate Request class instead of the Controller?**

6. **What does `'in:business,individual'` mean?**

7. **How would you make `tax_id` required only for business clients?**
