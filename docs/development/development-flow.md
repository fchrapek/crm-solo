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
