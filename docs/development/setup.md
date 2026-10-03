# Local Development Setup

## Prerequisites

- PHP 8.4+
- Composer
- Bun
- Docker

## Quick Start

```bash
# 1. Add local domain to /etc/hosts (one-time setup)
echo "127.0.0.1 crm-solo.test" | sudo tee -a /etc/hosts

# 2. Start infrastructure services
docker compose up -d

# 3. Copy environment file. It already points at 127.0.0.1 on the ports
#    docker compose publishes, so it needs no edits to boot.
cp .env.example .env

# 4. Install dependencies
composer install
bun install

# 5. Generate the app key and the Reverb key + secret
php artisan key:generate
php artisan setup:reverb-keys

# 6. Run migrations and seed
php artisan migrate --seed

# 7. Build assets
bun run build

# 8. Start the development server
composer run dev
```

The app is served at `https://crm-solo.test`: the hostname resolves to DDEV's traefik router, which forwards to the PHP dev server on `127.0.0.1:8101` (`composer run dev`). `127.0.0.1` and `::1` are the default trusted proxies (`TRUSTED_PROXIES`, `config/trustedproxy.php`) so the https scheme survives the hop, and Vite serves assets and HMR as `https://crm-solo.test:5180` / `wss://crm-solo.test:5180` (`vite.config.ts`, derived from `APP_URL`). That route needs DDEV's traefik and its certificate. Since 2026-09-21; the older `http://localhost:8000` address is gone.

`.env.example` ships `APP_URL=https://crm-solo.test`, and the session cookie is `Secure` whenever `APP_URL` is https (`SESSION_SECURE_COOKIE` unset). Without the DDEV route, set `APP_URL=http://127.0.0.1:8101` and open http://127.0.0.1:8101: `vite.config.ts` reads `APP_URL` (Vite's `loadEnv`) and, for plain http, serves assets as `http://127.0.0.1:5180` with ws HMR and CORS for `APP_URL`'s origin, and the cookie follows. With an https `APP_URL` the Vite settings are the ones above, unchanged. `VITE_PORT` moves Vite off 5180 if that port is taken.

The app answers only the hosts it is served under, in every environment: `APP_URL`'s host, `localhost`, `127.0.0.1`, `[::1]` and the comma-separated `APP_ALLOWED_HOSTS`. Any other `Host` (or `X-Forwarded-Host`) gets a plain 400 before routing, which is what stops a DNS-rebinding page from reaching a local instance. Reaching the app from another device by a LAN name means adding that name to `APP_ALLOWED_HOSTS`.

## Services

Ports are the ones published on the host, which is where `composer run dev`
runs PHP. They are deliberately off the defaults so the stack can coexist with
DDEV and with a client site's own services.

To run a second checkout alongside this one, set `COMPOSE_PROJECT_NAME` and a
different `DB_PORT` / `REDIS_PORT` / `MAIL_PORT` in its `.env`. Compose reads
the same variables Laravel does, so the container moves with the app. Skipping
this is not a harmless collision: the second stack fails to bind its port and
its app then reaches the first one's database, because `.env.example` ships
the same credentials in both.

| Service      | Host port | Description            |
|--------------|-----------|------------------------|
| Nginx        | 8080      | Reverse proxy          |
| App          | 8101      | Laravel dev server, reached via https://crm-solo.test |
| MariaDB      | 33061     | Database               |
| Valkey       | 63790     | Redis-compatible cache |
| Mailpit      | 18025     | Mail testing UI        |
| Mailpit SMTP | 10250     | SMTP server            |
| Reverb       | 8081      | WebSocket server, loopback only |
| Vite         | 5180      | Asset dev server       |

**Reverb** binds `127.0.0.1` (`REVERB_SERVER_HOST`, default in `config/reverb.php`), so nothing else on the network can subscribe to its channels, which are public by design (`useEchoPublic`). `.env.example` ships `REVERB_APP_KEY` and `REVERB_APP_SECRET` blank; `php artisan setup:reverb-keys` fills them with random values and leaves values you chose alone (`--force` rotates them, then restart Reverb and Vite). An install still carrying the old published `laravel-reverb-key` / `secret` pair is treated as blank, so running the command once replaces it.

## Runtime pieces

- **Queues**: Horizon, started by `composer run dev`. `.env.example` ships
  `QUEUE_CONNECTION=sync`, so jobs run inline until you set `redis`, which is
  what the owner runs locally. `config/horizon.php` defines `supervisor-1`
  (`default` queue, 60 s timeout) and `supervisor-long-running`
  (`long-running` queue, 1800 s, one try) for jobs that opt in with
  `onQueue('long-running')`; no job does today, and the Trello sync job sets
  its own 300 s timeout. Worker processes preload code: restart
  `composer run dev` after changing anything a job runs.
- **WebSockets**: Reverb on 8081, loopback only. Events broadcast on public
  channels (`SyncCompleted` as `.reverb.completed`, `SessionAttention` as
  `.reverb.session.attention` on `reverb.session.{taskId}`), so the frontend
  subscribes with `useEchoPublic`; the default `useEcho` is private and
  silently receives nothing.
- **Vite** on 5180, not 5173, so a client site's own Vite (WordPress themes
  default to 5173) can run beside it during a task preview.
- **Logs**: the `stack` channel writes `storage/logs/laravel.log` and, for
  warnings and up, `storage/logs/errors-YYYY-MM-DD.log` (kept 30 days).
  Terminal sessions log to `storage/logs/ttyd-task-{id}.log`.
- **Search** boxes use plain `LIKE`.
- **Scheduler** (`routes/console.php`): Trello sync every 5 minutes, the lead
  pull every 15, Infakt invoices daily, `sessions:reap` every 5 minutes, the
  local `db:backup` at 02:00 (local only), `demo:reset` at 03:30 (demo only).

## Optional Integrations (env vars)

These features degrade gracefully when the env var is unset — local dev works without them.

- `OPENAI_API_KEY` (and `OPENAI_REPORT_MODEL`, default `gpt-4o`): the AI report composer. Without it reports use the deterministic composer.
- `KIWWWI_LEADS_BASE_URL`, `KIWWWI_LEADS_EN_BASE_URL`, `KIWWWI_LEADS_USERNAME`, `KIWWWI_LEADS_APP_PASSWORD`: the scheduled lead pull (`kiwwwi:sync-leads`), one URL per site of the multisite (blog 1, blog 2). Unset, the scheduled pull is skipped; a blank site URL leaves only that site out.
- `TRELLO_ATTACHMENT_MAX_KB`, `TRELLO_ATTACHMENTS_TASK_MAX_KB`: caps on files pulled from Trello cards (25 MB per file, 100 MB per task by default).
- `APP_DISPLAY_TIMEZONE` — human-facing timezone for server-side surfaces that read or print a bare wall-clock time (currently `php artisan time:log --end`). Storage stays UTC (`app.timezone`); this only shifts input parsing and console output. Defaults to `UTC`, which keeps behaviour identical when unset — set it to `Europe/Warsaw` locally so `--end="11:00"` means 11:00 local. The browser is unaffected (it sends absolute ISO instants).

## Closed mode (waitlist)

`config/waitlist.php`, off by default, so local dev and the demo are unchanged.

- `APP_CLOSED=true`: guests are sent to the splash at `/czesc` from every page that needs a login (the `redirectGuestsTo` in `bootstrap/app.php`). `/login` keeps working for existing users, signed-in users never see the splash, and there is no registration route. With it off, `/czesc` is a 404.
- `WAITLIST_ACCOUNT_ID`: the account whose funnel receives signups. Required when closed; a wrong id makes `/czesc` answer 503 and log an error, on the first page load and on submit, rather than thanking the visitor.
- `WAITLIST_PIPELINE`: a pipeline slug from `config/leadgen.php`; empty means the first one. An unknown slug fails the same way as a wrong account.
- `WAITLIST_PER_MINUTE`, `WAITLIST_PER_HOUR`: signups per visitor IP (5 and 20). The IP is `$request->ip()`, so behind a reverse proxy set `TRUSTED_PROXIES` to the proxy's address or subnet (and `TRUST_CF_CONNECTING_IP=true` behind Cloudflare), or every visitor shares one bucket.
- `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET`: the same Turnstile gate as the login form, active only with the secret set.

A signup is a lead with source `waitlist`, created through `App\Services\Leads\LeadCapture::captureOnce()`: one lead per email in that account, deleted ones included, and the same thank-you for new, repeated and honeypot submissions. No user is ever created. Concurrent submissions of one address are settled by a unique `(account_id, capture_key)` index on `leads`.

The splash has to be reachable by guests. With Cloudflare Access in front of the host, add a bypass policy for `/czesc` (the page and its form POST), `/locales/*`, `/build/*` and `/up` only, and keep `/login` and everything else behind Access; otherwise host the splash somewhere Access does not cover.

## Default Credentials

**Database** (from `docker-compose.yaml`):
- Host: 127.0.0.1, port 33061
- Database: crm_solo
- Username: crm_solo
- Password: secret

**Seeded login** (`php artisan migrate --seed`):
- Email: webmaster@crm-solo.test
- Password: random, printed once by the seeder. Set `SEED_OWNER_PASSWORD` in `.env` before seeding to choose it instead. Reseeding never changes an existing owner's password. Under `DEMO_MODE` the seeder creates no owner; `DemoSeeder` owns the demo login.

## Common Commands

```bash
# Start/stop Docker services
docker compose up -d
docker compose down

# Development server (Docker + PHP server + Horizon + Vite + Scheduler + Reverb)
composer run dev

# Build for production
bun run build

# Run tests (serially or in parallel; CI runs parallel)
php artisan test
php artisan test --parallel

# Lint PHP
composer run lint

# Lint/format TypeScript
bun run lint
bun run format

# Everything CI checks, in one command (or one part: php, js, audit)
composer run check
composer run check -- js
```

## The CI gate

`.github/workflows/ci.yml` runs for every pull request (called by `pr.yml`)
and as the first job of the build workflows (`deploy.yml` in the private
source repository, `deploy-demo.yml` in the public one), so nothing is merged,
no image is built and the demo is not deployed from a commit that fails it. Each of its jobs runs
one part of `scripts/check.sh`, the script `composer run check` runs, so a
local pass means the same commands passed:

- **php**: `vendor/bin/pint --test`, then `php artisan test --parallel`.
- **js**: `php artisan wayfinder:generate` (the route helpers TypeScript
  imports are gitignored), `bun run types`, `bunx eslint .` (no `--fix`),
  `bun run lint:tokens`, `bun test` (the JS unit tests, `*.test.tsx`),
  `bun run build`.
- **audit**: `composer audit` and `bun audit` against the lock files.

The script expects an installed tree (`vendor/`, `node_modules/`, a `.env`
with `APP_KEY`). In CI the app boots against an empty in-memory SQLite and
the tests pin their own environment in `phpunit.xml`, so no service runs.

**Audit rule.** A high, critical or unrated advisory in a production Composer
package fails the run; dev-only and medium or low ones are printed and pass.
Any JS advisory of high or above fails, dev dependency or not, because
`package.json` keeps the browser bundle and its build chain (Vite sits in
`dependencies`) in one list and `bun audit` cannot tell them apart. The usual
fix is `composer update` / `bun update` within the existing constraints. An
advisory nobody can fix yet goes into `BUN_AUDIT_IGNORE` in
`scripts/check.sh` by GHSA id, with the reason beside it.

Not in the gate yet: `bun run format:check`, because 14 files under
`resources/` do not pass Prettier today.

## Branches, hooks and pull requests

Two long-lived branches: `develop`, where work lands, and `main`, which is
what runs in production. Everything else is short-lived, one change each,
cut from develop: `feat/<topic>`, `fix/<topic>`, `docs/<topic>`,
`agent/<topic>`.

**Local hooks.** `bun install` installs them (Lefthook, `lefthook.yml`).
pre-commit fixes staged PHP with Pint and staged scripts with ESLint, and
runs the token linter when CSS changed. pre-push runs the types and the JS
tests, plus the PHP suite when the push touches PHP, `lang/` or prompts.
commit-msg checks the house style with `scripts/hooks/commit-msg.sh`:
`Area: what changed`, the area from `.github/commit-areas.txt`, at most 72
characters, no em or en dashes. `LEFTHOOK=0` skips them once; CI runs
everything again regardless.

The automation below runs in the private source repository only: the jobs
that merge, open the release PR or guard main check that the repository is
private and not a fork, and `deploy.yml` and `main-guard.yml` are not part of
the public snapshot at all, so the public repository runs CI and the demo
deploy and nothing else.

**Pull requests into develop** (`pr.yml`): CI plus a title check (the title
becomes the squash commit subject, so it follows the same house style). When
both pass, the PR merges itself, squashed, unless develop moved since the
checks ran (then push again to re-check). The `hold` label or draft state
keeps it open; removing the label, marking it ready or fixing the title
checks and merges again. A PR that changes the gate itself (`.github/`, `scripts/check.sh`,
`scripts/hooks/`, the public export policy, `lefthook.yml`) is never merged by
the bot. Docs-only and
roadmap-only changes may still go straight to develop.

**Releases.** After every green develop build, `deploy.yml` keeps one open
pull request from develop to main, its body the subjects since the last
release. Merging it (a merge commit, not a squash) is the release.
`main-guard.yml` fails loudly on any push to main that is not exactly that
merge.

The source repository is private on GitHub's free plan, which has no branch
protection for private repositories, so the bot merge and the guard are
hygiene and an alarm, not a lock. The real
gate belongs on the deploy side: deploy a commit only when `Build Images`
succeeded for that exact sha (and, on main, the guard too).

Repository settings this relies on: default branch `develop`; squash commit
title and message from the PR; branches deleted after merge; Actions allowed
to create pull requests; labels `hold` and `destructive-migration`.

## Local Database Backups

`db:backup`, `db:backup:list` and `db:restore` are local dev tools. Real backups of a hosted instance are its server's job, not these commands.

```bash
php artisan db:backup            # gzipped dump into storage/backups/
php artisan db:backup:list
php artisan db:restore [file] [--force]
```

- The folder is `config('backup.path')` (`config/backup.php`, default `storage/backups`).
- One run at a time (a lock file in the folder; a second run, or a run whose timestamped name already exists, refuses). The dump runs under `pipefail` into an exclusively created `.partial-*` temp file and is renamed only once the `-- Dump completed` trailer is there; a failed or throwing run deletes its temp file and prunes nothing. Dump and restore run with no process timeout. Only a hard kill of PHP (SIGKILL, power loss) can leave a stray `.partial-*` file; nothing reads those, delete by hand. Retention (`--retention`, `--weekly-retention`) runs only after a verified dump.
- The password reaches `mariadb-dump` / `mariadb` through `MYSQL_PWD`, never the command line. The native binaries run with `--no-defaults`, so `~/.my.cnf` and other option files are ignored (a password or `skip-comments` there would otherwise win); the dump passes `--comments` explicitly because the completion check reads its trailer. When only Oracle MySQL's `mysqldump` / `mysql` are found, they also get `--no-login-paths` (MySQL 8.2+, detected from `--no-defaults --help`; if that probe fails the backup or restore stops rather than guess), because `--no-defaults` still lets them read a saved `.mylogin.cnf` password; an older MySQL client without the option still reads that file.
- The nightly `02:00` `db:backup` is scheduled when `APP_ENV=local`; a hosted install opts in with `BACKUP_SCHEDULE_AT=HH:MM` and needs `mariadb-dump` and bash in its image (`Dockerfile.prod` has neither). With no dump client and no running compose `mariadb` service, the command stops and says so instead of trying docker. `db:restore` refuses to run outside `local`, `--force` included.
- `DEMO_RESET_ALLOWED=true` lets `demo:reset` run by hand on a staging install with fictional data, without the rest of `DEMO_MODE` and without its nightly schedule. A production install with neither flag refuses `db:wipe` and `migrate:fresh`/`refresh`/`reset`.
- `BackupCommandsTest` never touches this folder or a real database: it uses a temp directory and fakes every process, with stray processes refused. The rest of the suite runs on in-memory SQLite because `phpunit.xml` pins `DB_CONNECTION`, `DB_DATABASE` and an empty `DB_URL` in both `$_SERVER` and the forced env, and points `APP_CONFIG_CACHE` at a file that never exists so a cached config cannot skip the pins. A test that builds its own connection is not covered by this.

## Agent Tasks — Terminal Sessions

The agent kanban launches a per-task terminal session backed by a git worktree + tmux + ttyd-served browser iframe. No executor pipeline; the CLI (claude or codex) runs interactively. The full contract is [terminal-sessions.md](terminal-sessions.md).

```bash
# One-time host install
brew install ttyd tmux

# claude or codex CLI on PATH:
npm i -g @anthropic-ai/claude-code  # or brew
claude login                         # so ~/.claude has a valid session
```

Each managed client repo should have `.worktrees/` in its `.gitignore` (the launcher appends it automatically on first session).

## Troubleshooting

**Redis class not found:**
Something set `REDIS_CLIENT=phpredis` without the PHP Redis extension being
installed. `.env.example` ships `predis`, which needs no extension.

**Database connection refused:**
Ensure Docker containers are running: `docker compose ps`

**Port already in use:**
Stop other services using the same ports or modify `docker-compose.yaml`.
