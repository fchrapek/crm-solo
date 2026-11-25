# Development Flow

## Feature Implementation Checklist

When implementing a new feature, follow this order:

### 1. Plan
- Review or create a plan document
- Break the feature into logical branches/steps
- Identify files to create and modify

### 2. Implement
- Create migrations first (if DB changes needed)
- Create models with casts, relationships, scopes
- Create services (business logic)
- Create controllers
- Create frontend pages (TSX + CSS Modules)
- Add routes to `routes/web.php`
- Add translations to `lang/en.json` and `lang/pl.json`
- Generate Wayfinder routes: `php artisan wayfinder:generate`

### 3. Test
- **Write tests immediately after implementation, not later.**
- Unit tests (`tests/Unit/`) for:
  - Service methods (parsing, matching, business logic)
  - Model methods and casts
  - Utility/helper functions
- Feature tests (`tests/Feature/`) for:
  - Controller endpoints (auth, validation, response format)
  - Integration flows (service + DB)
- Run: `php artisan test`

### 4. Lint & Type Check
- PHP: `composer run lint` (Pint auto-fix)
- TypeScript: `bun run types`
- ESLint: `bun run lint`

### 5. Verify in Browser
- Test the UI manually
- Check both EN and PL translations
- Verify mobile responsiveness

## Testing Guidelines

### Test Structure
```
tests/
├── Unit/           # Pure unit tests (no DB, no HTTP)
│   ├── IntegrationModelTest.php
│   └── LeadgenConfigTest.php
├── Feature/        # Integration tests (DB, HTTP, Inertia)
│   ├── ClientsTest.php
│   ├── ContactsTest.php
│   └── TrelloSyncTest.php
├── Pest.php
└── TestCase.php
```

### Conventions
- Use `RefreshDatabase` trait in feature tests
- Use `actingAs($user)` for authenticated endpoints
- Mock external APIs (AI providers, third-party HTTP APIs) — never hit real APIs in tests
- Use Inertia's `assertInertia()` for page responses
- Add `->etc()` to Inertia assertions that don't check all properties

### Running Tests
```bash
php artisan test                              # All tests
php artisan test tests/Unit/                  # Unit tests only
php artisan test tests/Feature/               # Feature tests only
php artisan test --filter=ClientsTest         # Specific test class
```

## Common Patterns

### Adding a new integration
1. Add to `Integration::PROVIDERS` constant
2. Create service in `app/Services/Integrations/`
3. Handle in `IntegrationsController::sync()`
4. Update frontend `edit.tsx` if auth flow differs

### Adding translations
Always add to both `lang/en.json` and `lang/pl.json` simultaneously.

### Renaming or retiring a URL
Old URLs live in one table, `App\Http\LegacyUrls`: a path pattern to its new path, and per new path the old query keys (and values) to their new names. The global middleware `RedirectLegacyUrls` answers a GET or HEAD on an old URL with a 301 (`Cache-Control: public, max-age=604800`, a relative `Location`), translating path and query in one hop and keeping every other parameter in its order. It runs before routing and authentication, so a guest's old bookmark is translated before the login redirect saves it.
1. Rename the route and every place that builds the URL.
2. Add the old URL as a row of `LegacyUrls` and as a row of the `table()` provider in `LegacyUrlsTest`, the review list of every redirect; the test fails on a rule or a mapped value with no row.
3. Never redirect or alias a write (POST, PUT, PATCH, DELETE): `fetch()` follows a 301 on a POST as a GET, so the write is lost without an error. An old write path answers 404. Never use `Route::redirect()` either: Laravel's `RedirectController` drops the query string.
4. A retired Polish word also goes on the list in `EnglishUrlsGuardTest`, which fails when one comes back as a route segment, a record tab or a string under `app/Http` (only `LegacyUrls.php` is skipped, and it may hold the words only inside its two tables).
