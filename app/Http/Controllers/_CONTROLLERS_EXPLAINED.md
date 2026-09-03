# Controllers - Explained Like I'm 5

## What is a Controller?

**ELI5:** A Controller is like a waiter in a restaurant.

```
Customer (Browser)     Waiter (Controller)      Kitchen (Model/Database)
        │                      │                         │
        │  "I want pizza"      │                         │
        ├─────────────────────►│                         │
        │                      │   "Make pizza"          │
        │                      ├────────────────────────►│
        │                      │                         │
        │                      │   "Here's the pizza"    │
        │                      │◄────────────────────────┤
        │   "Your pizza"       │                         │
        │◄─────────────────────┤                         │
```

- Takes your request (URL)
- Talks to the kitchen (database via Model)
- Brings back the response (page)

---

## RESTful Pattern

Controllers follow a standard naming pattern called REST:

| Method | URL | Function | Action |
|--------|-----|----------|--------|
| GET | `/clients` | `index()` | List all |
| GET | `/clients/create` | `create()` | Show create form |
| POST | `/clients` | `store()` | Save new |
| GET | `/clients/5/edit` | `edit()` | Show edit form |
| PUT | `/clients/5` | `update()` | Save changes |
| DELETE | `/clients/5` | `destroy()` | Delete |

---

## Controller Functions Explained

### 1. `index()` - List all

```php
public function index()
{
    return Inertia::render('clients/index', [
        'clients' => new ClientCollection(
            Auth::user()->account->clients()->paginate()
        ),
    ]);
}
```

**ELI5:** "Show me all my clients"

| Part | What it does |
|------|--------------|
| `Inertia::render('clients/index', [...])` | Display the React page with this data |
| `Auth::user()->account->clients()` | Get clients for current user's account |
| `->paginate()` | Split into pages (10 per page) |

---

### 2. `create()` - Show empty form

```php
public function create()
{
    return Inertia::render('clients/create');
}
```

**ELI5:** "Show me the form to add a new client"

Just displays the page. No data needed.

---

### 3. `store()` - Save new record

```php
public function store(ClientsRequest $request): RedirectResponse
{
    Auth::user()->account->clients()->create($request->validated());

    return Redirect::route('clients.index')->with('success', '...');
}
```

**ELI5:** "Save this new client to the database"

| Part | What it does |
|------|--------------|
| `ClientsRequest $request` | Form data, already validated |
| `$request->validated()` | Get only the safe, validated fields |
| `->create(...)` | Insert into database |
| `Redirect::route(...)` | Go back to the list |
| `->with('success', ...)` | Show success message |

---

### 4. `edit()` - Show form with existing data

```php
public function edit(Client $client)
{
    return Inertia::render('clients/edit', [
        'client' => new ClientResource($client),
    ]);
}
```

**ELI5:** "Show me the form to edit this client"

| Part | What it does |
|------|--------------|
| `Client $client` | Route Model Binding - Laravel finds the client |
| `new ClientResource($client)` | Transform data for frontend |

---

### 5. `update()` - Save changes

```php
public function update(Client $client, ClientsRequest $request): RedirectResponse
{
    $client->update($request->validated());

    return Redirect::back()->with('success', '...');
}
```

**ELI5:** "Save the changes to this client"

---

### 6. `destroy()` - Delete

```php
public function destroy(Client $client): RedirectResponse
{
    $client->delete();

    return Redirect::back()->with('success', '...');
}
```

**ELI5:** "Delete this client" (soft delete - sets `deleted_at`)

---

### 7. `restore()` - Restore deleted

```php
public function restore(Client $client): RedirectResponse
{
    $client->restore();

    return Redirect::back()->with('success', '...');
}
```

**ELI5:** "Bring back this deleted client"

---

## Key Concepts

### Inertia::render()

```php
Inertia::render('clients/index', ['clients' => $data]);
```

This connects Laravel to React:
- First param: which React page to show (`resources/js/pages/clients/index.tsx`)
- Second param: data to pass to that page (as props)

---

### Route Model Binding

```php
public function edit(Client $client)
```

When URL is `/clients/5`, Laravel automatically:
1. Sees `Client $client` in the signature
2. Finds Client with ID 5
3. Passes it to your function

You don't write `Client::find($id)` - Laravel does it for you.

---

### Request Validation

```php
public function store(ClientsRequest $request)
```

`ClientsRequest` is a separate class that validates the form:
- Is name provided?
- Is email valid?
- Is phone not too long?

If validation fails, Laravel automatically redirects back with errors.

---

### Resources

```php
new ClientResource($client)
new ClientCollection($clients)
```

These transform Model data for the frontend:
- Hide sensitive fields
- Format dates
- Include relationships

---

## Controller vs Model - What Goes Where?

| Put in Controller | Put in Model |
|-------------------|--------------|
| Request handling | Data logic |
| Which page to show | How to query/filter |
| Redirects | Relationships |
| One-time logic | Reusable logic |

**ELI5:**
- **Controller** = Traffic cop (directs requests)
- **Model** = Data expert (knows how to find/filter data)

### Example: Filter Logic

❌ **Bad:** Filter in Controller (copied everywhere)
```php
// ClientsController
if ($search) {
    $query->where('name', 'like', '%' . $search . '%');
}

// ReportsController - same code copied!
if ($search) {
    $query->where('name', 'like', '%' . $search . '%');
}
```

✅ **Good:** Filter in Model (reusable)
```php
// Client.php (Model) - defined ONCE
public function filter($query, $filters) { ... }

// Any controller - just use it
Client::filter($filters)->get();
```

---

## Questions to Test Yourself

1. **What is the role of a Controller?**

2. **What does `Inertia::render()` do?**

3. **Why don't we write `Client::find($id)` in the edit function?**

4. **What is the difference between `store()` and `update()`?**

5. **Why is filter logic in the Model instead of Controller?**

6. **What does `$request->validated()` return?**

7. **What's the difference between `Redirect::route()` and `Redirect::back()`?**
