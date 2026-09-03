# Resources - Explained Like I'm 5

## What is a Resource?

**ELI5:** A Resource is like a gift wrapper.

```
Raw Data (unwrapped)          Resource (wrapper)           Frontend (receives gift)
                                                          
┌─────────────────────┐      ┌─────────────────────┐      ┌─────────────────────┐
│ id: 1               │      │                     │      │ id: 1               │
│ account_id: 1       │      │   "Hide account_id, │      │ name: "Acme"        │
│ name: "Acme"        │ ───► │    password, and    │ ───► │ email: "a@b.test"    │
│ password: "secret"  │      │    other secrets"   │      │ phone: "123456"     │
│ email: "a@b.test"    │      │                     │      │                     │
│ created_at: ...     │      └─────────────────────┘      └─────────────────────┘
│ updated_at: ...     │                                   
└─────────────────────┘      Only what frontend needs
     Everything                                           
```

The Resource decides:
- What data to show
- What data to hide
- How to format it

---

## Two Types of Resources

| Type | Purpose | When to use |
|------|---------|-------------|
| `JsonResource` | Single record | Edit page - one client |
| `ResourceCollection` | List of records | Index page - many clients |

---

## Single Resource (ClientResource)

```php
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
            'deleted_at' => $this->deleted_at,
            'contacts' => $this->contacts()->orderByName()->get()->map->only('id', 'name', 'city', 'phone'),
        ];
    }
}
```

**Used in Controller:**
```php
return Inertia::render('clients/edit', [
    'client' => new ClientResource($client),
]);
```

All details for one client - used on edit/detail pages.

---

## Collection Resource (ClientCollection)

```php
final class ClientCollection extends ResourceCollection
{
    public function toArray(Request $request): Collection
    {
        return $this->collection->map->only(
            'id', 'type', 'name', 'phone', 'city', 'deleted_at'
        );
    }
}
```

**Used in Controller:**
```php
return Inertia::render('clients/index', [
    'clients' => new ClientCollection($paginatedClients),
]);
```

Only essential fields - used on list pages (faster, less data).

---

## Why Use Resources?

### 1. Security - Hide sensitive data

```php
// Without Resource - everything exposed!
return response()->json($client);  // includes account_id, internal fields...

// With Resource - only what you specify
return new ClientResource($client);  // controlled output
```

### 2. Consistency - Same format everywhere

```php
// API endpoint
return new ClientResource($client);

// Inertia page
return Inertia::render('clients/edit', [
    'client' => new ClientResource($client),
]);

// Both get the same data format
```

### 3. Transformation - Format data for frontend

```php
return [
    'created_at' => $this->created_at->format('Y-m-d'),  // Format date
    'full_address' => $this->address . ', ' . $this->city,  // Combine fields
    'is_business' => $this->type === 'business',  // Compute values
];
```

---

## The `$this` Magic

Inside a Resource, `$this` refers to the wrapped model:

```php
public function toArray(Request $request): array
{
    // $this = the Client model that was passed in
    return [
        'id' => $this->id,
        'name' => $this->name,
        'contacts' => $this->contacts,  // Access relationships too
    ];
}
```

---

## The `@mixin` Annotation

```php
/**
 * @mixin Client
 */
final class ClientResource extends JsonResource
```

This is for your IDE only - tells it "when I type `$this->`, show me Client properties."

Doesn't affect how the code runs.

---

## Including Relationships

```php
'contacts' => $this->contacts()->orderByName()->get()->map->only('id', 'name', 'city', 'phone'),
```

| Part | What it does |
|------|--------------|
| `$this->contacts()` | Get the relationship query |
| `->orderByName()` | Sort by name |
| `->get()` | Execute query, get results |
| `->map->only(...)` | For each contact, keep only these fields |

---

## Collection vs Single - Different Fields

| Page | Resource | Fields |
|------|----------|--------|
| List (index) | `ClientCollection` | id, type, name, phone, city (minimal) |
| Edit | `ClientResource` | All fields + contacts (complete) |

Why? List pages don't need all data. Less data = faster page load.

---

## How It Flows

```
Database                                          
    │                                             
    ▼                                             
Model (Client)                                    
    │ All fields + relationships                  
    ▼                                             
Resource (ClientResource)                         
    │ Filters & transforms                        
    ▼                                             
JSON sent to frontend                             
    │ Only specified fields                       
    ▼                                             
React component receives props                    
```

---

## Questions to Test Yourself

1. **What is the purpose of a Resource?**

2. **What's the difference between `ClientResource` and `ClientCollection`?**

3. **Why would you include fewer fields in a Collection?**

4. **Inside a Resource, what does `$this` refer to?**

5. **What does `@mixin Client` do?**

6. **How do you include a relationship in the Resource output?**

7. **Why is it better to use Resources than returning models directly?**
