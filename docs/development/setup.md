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

# 5. Generate app key
php artisan key:generate

# 6. Run migrations and seed
php artisan migrate --seed

# 7. Build assets
bun run build

# 8. Start the development server
composer run dev
```

The app will be available at `http://crm-solo.test`

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
| App          | 8000      | Laravel application    |
| MariaDB      | 33061     | Database               |
| Valkey       | 63790     | Redis-compatible cache |
| Mailpit      | 18025     | Mail testing UI        |
| Mailpit SMTP | 10250     | SMTP server            |
| Reverb       | 8081      | WebSocket server       |
| Vite         | 5180      | Asset dev server       |

## Optional Integrations (env vars)

These features degrade gracefully when the env var is unset — local dev works without them.

- `FIGMA_API_TOKEN` — Personal Access Token from https://www.figma.com/developers/api#access-tokens (read scope is enough). Powers `FigmaDesignReferenceProvider` so per-task design references download as cached PNGs the agent can `Read`. When unset, design-ref URLs still surface in the brief but no local PNG is fetched.
- `OPENAI_API_KEY` — required only when running tasks with GPT-5 or any `gpt-*` model (compiler OR executor). Claude-only setups don't need it.
- `APP_DISPLAY_TIMEZONE` — human-facing timezone for server-side surfaces that read or print a bare wall-clock time (currently `php artisan time:log --end`). Storage stays UTC (`app.timezone`); this only shifts input parsing and console output. Defaults to `UTC`, which keeps behaviour identical when unset — set it to `Europe/Warsaw` locally so `--end="11:00"` means 11:00 local. The browser is unaffected (it sends absolute ISO instants).

## Default Credentials

**Database** (from `docker-compose.yaml`):
- Host: 127.0.0.1, port 33061
- Database: crm_solo
- Username: crm_solo
- Password: secret

**Seeded login** (`php artisan migrate --seed`):
- Email: webmaster@crm-solo.test
- Password: test1234

## Common Commands

```bash
# Start/stop Docker services
docker compose up -d
docker compose down

# Development server (Docker + PHP server + Horizon + Vite + Scheduler + Reverb)
composer run dev

# Build for production
bun run build

# Run tests
php artisan test

# Lint PHP
composer run lint

# Lint/format TypeScript
bun run lint
bun run format
```

## Agent Tasks — Terminal Sessions

The agent kanban launches a per-task terminal session backed by a git worktree + tmux + ttyd-served browser iframe. No executor pipeline; the CLI (claude or codex) runs interactively. See the **Terminal Sessions** section in `CLAUDE.md` for the full contract.

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
