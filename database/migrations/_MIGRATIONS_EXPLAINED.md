# Migrations - Explained Like I'm 5

## What is a Migration?

**ELI5:** Migrations are like LEGO instructions for your database.

Instead of manually building tables with SQL, you write PHP instructions. Anyone with these instructions can build the exact same database.

| Benefit | Explanation |
|---------|-------------|
| **Trackable** | Each migration is a file in git - you see the history |
| **Reversible** | Every `up()` has a `down()` to undo it |
| **Shareable** | Team runs `php artisan migrate` and gets the same DB |
| **Database agnostic** | Same code works on MySQL, PostgreSQL, SQLite |

---

## File Structure

```php
return new class extends Migration
{
    public function up(): void
    {
        // What to do when migrating FORWARD
    }

    public function down(): void
    {
        // What to do when ROLLING BACK (undo)
    }
};
```

---

## Pure PHP vs Laravel

| PHP (language) | Laravel (framework) |
|----------------|---------------------|
| `<?php` | `Schema::` (Facade) |
| `declare(strict_types=1)` | `Blueprint` class |
| `use Some\Class` | `Migration` class |
| `return new class` | `$table->string()` |
| `function () {}` (closure) | `$table->timestamps()` |
| `public function up(): void` | `->nullable()`, `->index()` |

**Remember:** Laravel is just PHP code underneath. It's "PHP all the way down."

---

## Key Concepts

### 1. Facade

**ELI5:** A Facade is like a TV remote control.

Instead of walking to the TV, finding the power button, and pressing it - you just press the remote.

```php
// Without Facade (complicated)
$schemaBuilder = app()->make('db')->connection()->getSchemaBuilder();
$schemaBuilder->create('clients', ...);

// With Facade (simple)
Schema::create('clients', ...);
```

A Facade is a **wrapper** that gives you easy access to complex stuff inside Laravel.

---

### 2. Closure (Anonymous Function)

**ELI5:** A closure is like ordering a pizza.

Pizza shop: "I'll make the dough, heat the oven, and deliver. You just tell me what toppings you want."

```php
PizzaShop::makePizza(function ($pizza) {
    $pizza->addTopping('pepperoni');
    $pizza->addTopping('mushrooms');
});
```

In migrations:

```php
Schema::create('clients', function (Blueprint $table) {
    $table->string('name', 100);
    $table->string('tax_id', 20);
});
```

| Who | Does what |
|-----|-----------|
| **Laravel** | Creates table, handles DB connection, runs SQL |
| **You** (in the closure) | Just list the columns you want |

**Pattern:** "I (the method) do the boring/complex stuff. You (the closure) do your specific thing."

---

### 3. Blueprint

The `$table` variable is a `Blueprint` object - it has methods to add columns:

```php
$table->string('name', 100);      // VARCHAR(100)
$table->integer('account_id');    // INT
$table->text('notes');            // TEXT (long string)
$table->json('external_ids');     // JSON
$table->enum('type', ['a', 'b']); // ENUM (only these values allowed)
$table->timestamps();             // created_at + updated_at
$table->softDeletes();            // deleted_at
```

**Chaining modifiers:**

```php
$table->string('tax_id', 20)->nullable()->index();
//     │                  │   │           │
//     │                  │   │           └── Add database index
//     │                  │   └── Can be NULL
//     │                  └── Max 20 characters
//     └── Column name
```

---

### 4. String Length

```php
$table->string('tax_id', 20);
```

The `20` = maximum 20 characters. In SQL: `VARCHAR(20)`.

Default (if not specified) is 255.

---

### 5. `declare(strict_types=1)`

Makes PHP strict about types:

```php
function add(int $a, int $b): int {
    return $a + $b;
}

add("5", "3");  // Without strict: returns 8 (auto-converts)
                // With strict: ERROR! Must be int, not string
```

---

## Common Commands

```bash
php artisan make:migration create_something_table   # Create new migration
php artisan migrate                                  # Run all pending migrations
php artisan migrate:rollback                         # Undo last batch
php artisan migrate:fresh                            # Drop all tables, re-run all migrations
php artisan migrate:status                           # Show which migrations have run
```

---

## Questions to Test Yourself

1. **What does `Schema::create()` do, and why is `Schema` called a "Facade"?**

2. **In `function (Blueprint $table) { ... }`, what is the closure's job vs Laravel's job?**

3. **What's the difference between `$table->string('name')` and `$table->string('name', 50)`?**

4. **Why do migrations have both `up()` and `down()` methods?**

5. **What does `->nullable()` mean, and when would you use it?**

6. **If Laravel is "just PHP underneath," what does Laravel actually provide?**

7. **Why use migrations instead of writing SQL directly?**

---

## Your Current Migration: `create_clients_table.php`

```php
Schema::create('clients', function (Blueprint $table) {
    $table->increments('id');                                        // Auto-increment primary key
    $table->integer('account_id')->index();                          // Foreign key to accounts
    $table->enum('type', ['business', 'individual'])->default('business'); // Client type
    $table->string('name', 100);                                     // Company/person name
    $table->string('email', 50)->nullable();                         // Email (optional)
    $table->string('phone', 50)->nullable();                         // Phone (optional)
    $table->string('address', 150)->nullable();                      // Street address
    $table->string('city', 50)->nullable();
    $table->string('region', 50)->nullable();                        // Province/state
    $table->string('country', 2)->nullable();                        // Country code (PL, US, etc.)
    $table->string('postal_code', 25)->nullable();                   // e.g., 41-300
    $table->string('tax_id', 20)->nullable();                        // NIP (Polish tax ID)
    $table->string('business_type', 100)->nullable();                // e.g., "sp. z o.o."
    $table->text('notes')->nullable();                               // Free-form notes
    $table->json('external_ids')->nullable();                        // {infakt: "123", clockify: "abc"}
    $table->timestamps();                                            // created_at, updated_at
    $table->softDeletes();                                           // deleted_at (for soft delete)
});
```
