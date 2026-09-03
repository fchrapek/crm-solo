# Models - Explained Like I'm 5

## What is a Model?

**ELI5:** A Model is a PHP class that represents a database table.

```
Client model  ←→  clients table
Contact model ←→  contacts table
User model    ←→  users table
```

Each row in the table = one instance of the model.

---

## What is Eloquent?

Eloquent is Laravel's **ORM** (Object-Relational Mapper).

**ELI5:** It lets you talk to your database using PHP objects instead of SQL.

| Without Eloquent (raw SQL) | With Eloquent |
|----------------------------|---------------|
| `SELECT * FROM clients WHERE id = 5` | `Client::find(5)` |
| `INSERT INTO clients (name) VALUES ('Acme')` | `Client::create(['name' => 'Acme'])` |
| `UPDATE clients SET name = 'New' WHERE id = 5` | `$client->update(['name' => 'New'])` |
| `DELETE FROM clients WHERE id = 5` | `$client->delete()` |

When you write `class Client extends Model`, you get all these superpowers for free.

---

## Model Structure

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Client extends Model
{
    use HasFactory, SoftDeletes;    // Traits (reusable features)

    // Relationships
    // Scopes
    // Custom methods
}
```

---

## Key Concepts

### 1. Traits

```php
use Concerns\Filterable, HasFactory, Searchable, SoftDeletes;
```

**ELI5:** Traits are like LEGO pieces you snap onto your model.

| Trait | What it adds |
|-------|--------------|
| `HasFactory` | Create test data: `Client::factory()->create()` |
| `SoftDeletes` | "Delete" just sets `deleted_at` instead of removing the row |
| `Searchable` | Full-text search via Typesense |
| `Filterable` | Custom search/filter logic |

---

### 2. Relationships

```php
public function contacts(): HasMany
{
    return $this->hasMany(Contact::class);
}
```

**ELI5:** "One client has many contacts."

Laravel uses `client_id` in the contacts table to connect them.

```php
$client->contacts;  // Returns all contacts for this client
```

| Relationship Type | Example |
|-------------------|---------|
| `hasMany` | Client has many Contacts |
| `belongsTo` | Contact belongs to Client |
| `hasOne` | User has one Profile |
| `belongsToMany` | User belongs to many Roles |

---

### 3. Route Model Binding

**ELI5:** When you visit `/clients/5`, Laravel automatically finds Client #5 for you.

```php
// Instead of this:
public function edit($id)
{
    $client = Client::findOrFail($id);  // Manual lookup
}

// You just write:
public function edit(Client $client)    // Laravel does it for you!
{
}
```

#### How it works:

```
URL: /clients/5
         │
         ▼
Laravel Router sees {client} = 5
         │
         ▼
Laravel sees "Client $client" in method signature
         │
         ▼
Laravel internally calls:
    $instance = new Client();
    $client = $instance->resolveRouteBinding(5);
         │
         ▼
Your controller receives Client #5
```

#### The `resolveRouteBinding` method:

```php
public function resolveRouteBinding($value, $field = null): ?Model
{
    return $this->where($field ?? 'id', $value)->withTrashed()->firstOrFail();
}
```

| Part | Meaning |
|------|---------|
| `$value` | The `5` from `/clients/5` |
| `$field ?? 'id'` | Which column to search (default: `id`) |
| `->withTrashed()` | Also look in deleted records |
| `->firstOrFail()` | Find one or throw 404 |

**Why override?** The default Laravel method doesn't include trashed records. Your override does.

---

### 4. Static vs Instance Methods

| Syntax | Meaning |
|--------|---------|
| `Client::find(5)` | Static - call on the class itself |
| `$this->where(...)` | Instance - call on a specific object |

```php
// Static: "Hey Client class, find #5"
Client::find(5);

// Instance: "Hey this specific client object, do something"
$client = new Client();
$client->where('id', 5);
```

Eloquent is clever - `$this->where()` on an empty instance starts a query on the table.

---

### 5. Scopes

```php
#[Scope]
public function filter(Builder $query, array $filters, int $accountId): void
{
    $query
        ->when($filters['search'] ?? null, fn ($query, $search) => ...)
        ->when($filters['trashed'] ?? null, fn ($query, $trashed) => ...);
}
```

**ELI5:** Scopes are reusable query chunks.

Instead of repeating filter logic everywhere, define it once:

```php
// Without scope:
Client::where('account_id', $id)->where('trashed', true)->get();

// With scope:
Client::filter($filters, $accountId)->get();
```

---

### 6. Events / Hooks

```php
protected static function booted(): void
{
    self::updated(function (Client $client): void {
        if ($client->isDirty('name')) {
            $client->contacts->searchable();
        }
    });
}
```

**ELI5:** "When something happens to a model, do this."

| Event | When it fires |
|-------|---------------|
| `creating` | Before a new record is saved |
| `created` | After a new record is saved |
| `updating` | Before an existing record is updated |
| `updated` | After an existing record is updated |
| `deleting` | Before a record is deleted |
| `deleted` | After a record is deleted |

In this example: "When client name changes, re-index their contacts for search."

---

## Common Model Methods

| Method | What it does |
|--------|--------------|
| `Client::find(5)` | Find by ID |
| `Client::findOrFail(5)` | Find or throw 404 |
| `Client::all()` | Get all records |
| `Client::where('city', 'Warsaw')->get()` | Filter records |
| `Client::create([...])` | Create new record |
| `$client->update([...])` | Update record |
| `$client->delete()` | Delete (or soft-delete) |
| `$client->restore()` | Restore soft-deleted record |
| `$client->isDirty('name')` | Did this field change? |

---

## Questions to Test Yourself

1. **What is the difference between a Model and a table?**

2. **What does `extends Model` give you?**

3. **When you write `Client::find(5)`, what is Laravel doing under the hood?**

4. **What is Route Model Binding and why is it useful?**

5. **Why would you override `resolveRouteBinding`?**

6. **What's the difference between `Client::find(5)` and `$this->where('id', 5)`?**

7. **What does `SoftDeletes` trait do?**

8. **What is a Scope and when would you use one?**
