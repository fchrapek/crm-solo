# Lite vs Premium Tier — Research

## Goal

Run a free publicly visible version (Lite) alongside the full version (Premium) from a **single codebase**. No second repo, no second deploy pipeline.

## Current State

- **Account model** is the multi-tenant boundary — all resources scoped by `account_id`
- **No plan/tier system** — Account only has `name` field
- **No registration flow** — accounts are seeded, login only
- **No policies/gates** — authorization is manual `account_id` checks
- **HandleInertiaRequests** shares `auth.user` and `auth.account` to frontend
- User role system is a simple `owner` boolean

## Approaches Evaluated

### 1. Laravel Pennant (Feature Flags)

First-party feature flag system. Define features as closures that resolve per-scope.

- **Pros:** Elegant API, supports class-based features, built-in DB caching
- **Cons:** Designed for gradual rollouts/A/B tests, not static plan tiers. Every feature flag would just check the same `plan` column — unnecessary indirection
- **Verdict:** Overkill for a binary free/premium split

### 2. Gate/Policy System

Use `Gate::define()` for plan checks, `Gate::before()` to grant all to premium.

- **Pros:** Built-in, works with middleware (`can:feature-name`)
- **Cons:** Semantically wrong — gates are for "can this user do X?" not "does this plan include X?". Mixing plan checks with permission checks gets messy
- **Verdict:** Workable but conceptually muddy

### 3. Laravel Cashier / Spark

Full subscription and billing management.

- **Verdict:** Complete overkill. We need tier gating, not payment processing. Can add Cashier later if payments are needed — it coexists with any gating approach

### 4. `plan` Column + Middleware

Add `plan` enum to `accounts` table. Middleware checks plan on premium routes.

- **Pros:** Minimal — one migration, one middleware, one shared Inertia prop
- **Cons:** No structured way to define which features belong to which plan
- **Verdict:** Simplest option, good baseline

### 5. Config-Based Feature Flags (env vars)

`.env` values like `FEATURE_EMAIL_SYNC=true`.

- **Pros:** Dead simple
- **Cons:** Global, not per-account. Useless for multi-tenant tier gating
- **Verdict:** Not suitable. Fine for deployment-level toggles only

### 6. `HasPlan` Trait + `config/plans.php` (Recommended)

Config-driven feature matrix with a trait on Account model.

- **Pros:** Single source of truth in one config file. Clean `$account->canAccess('feature')` API. Easy to share to frontend. Testable. No packages
- **Cons:** Slightly more setup than plain middleware, but more maintainable
- **Verdict:** Best balance of simplicity and scalability

## Recommendation

**Approach 6** backed by **Approach 4**: `plan` column on accounts + `HasPlan` trait + `config/plans.php`.

### Implementation Plan

#### 1. Migration

Add `plan` enum column to `accounts` table:
```
'lite' | 'premium' (default: 'premium' for existing accounts)
```

#### 2. Config — `config/plans.php`

Single source of truth for the feature matrix:

```php
return [
    'lite' => [
        'clients',
        'contacts',
        'manual-tasks',
    ],
    'premium' => [
        'clients',
        'contacts',
        'manual-tasks',
        'email-sync',
        'email-to-task',
        'ai-agent',
        'ai-priority',
        'trello-sync',
        'trello-onboarding',
        'infakt-sync',
        'integrations-page',
    ],
];
```

#### 3. Trait — `HasPlan` on Account model

```php
trait HasPlan
{
    public function canAccess(string $feature): bool
    {
        $features = config("plans.{$this->plan}", []);
        return in_array($feature, $features);
    }

    public function availableFeatures(): array
    {
        return config("plans.{$this->plan}", []);
    }

    public function isPremium(): bool
    {
        return $this->plan === 'premium';
    }
}
```

#### 4. Middleware — `CheckFeature`

Route-level gating:

```php
// Route definition
Route::middleware('feature:email-sync')->group(function () {
    Route::resource('emails', EmailsController::class);
});

// Middleware
public function handle($request, Closure $next, string $feature)
{
    if (!$request->user()?->account->canAccess($feature)) {
        abort(403);
    }
    return $next($request);
}
```

#### 5. Inertia Shared Props

Extend `HandleInertiaRequests` to share plan info:

```php
'account' => [
    'id' => $account->id,
    'name' => $account->name,
    'plan' => $account->plan,
    'features' => $account->availableFeatures(),
],
```

#### 6. Frontend — React hook

```tsx
function useFeature(feature: string): boolean {
    const { auth } = usePage().props;
    return auth.account.features.includes(feature);
}

// Usage in components
const canUseEmail = useFeature('email-sync');
{canUseEmail && <EmailTab />}
```

#### 7. Public Demo Mode

For unauthenticated visitors (Lite public version):
- Separate guest-accessible routes for Lite features
- Resolve plan as `'lite'` when no user is authenticated
- Seed a demo account with `plan = 'lite'` and read-only sample data

### Route Organization

```php
// Open to all authenticated users (Lite + Premium)
Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class);
    Route::resource('clients', ClientsController::class);
    Route::resource('contacts', ContactsController::class);
});

// Premium only
Route::middleware(['auth', 'feature:email-sync'])->group(function () {
    Route::resource('emails', EmailsController::class);
});

Route::middleware(['auth', 'feature:integrations-page'])->group(function () {
    Route::get('/integrations', [IntegrationsController::class, 'index']);
    // ...
});
```

### Feature Split

| Feature | Lite | Premium |
|---------|------|---------|
| Clients CRUD | ✅ | ✅ |
| Contacts CRUD | ✅ | ✅ |
| Manual tasks | ✅ | ✅ |
| Dashboard (basic) | ✅ | ✅ |
| Gmail sync | ❌ | ✅ |
| Email-to-task pipeline | ❌ | ✅ |
| AI agent chat | ❌ | ✅ |
| AI priority estimation | ❌ | ✅ |
| Trello sync | ❌ | ✅ |
| Trello onboarding | ❌ | ✅ |
| Infakt sync | ❌ | ✅ |
| Integrations page | ❌ | ✅ |

### Prerequisites

- Registration flow (currently no signup — accounts are seeded)
- Decide if Lite needs its own landing page or just a limited dashboard
- Decide if demo mode is read-only or allows creating test data
