# CRM Solo

A CRM for a one-person web agency. It tracks clients, retainers, projects,
tasks, time and leads, and it runs the monthly close: pull each maintained
site's database, update it, verify it, write the client's report, draft the
invoice.

It is local-first. The real instance runs on a laptop next to the work it
describes, which is why it can do things a hosted CRM cannot: read the git
repositories it has on disk, open a terminal session inside a task's worktree,
and start the project's dev server to look at the change.

A read-only demo with fictional data runs at
[demo.crm-solo.com](https://demo.crm-solo.com).

## Stack

Laravel 12 on PHP 8.4, Inertia v2 with React 19 and TypeScript, MariaDB,
Valkey, Horizon and Reverb. Bun for the frontend. Styling is CSS Modules, not
Tailwind. Docker runs the local infrastructure; FrankenPHP and Octane serve
production.

## Running it

```bash
git clone <your fork> crm-solo && cd crm-solo
composer install && bun install
cp .env.example .env && php artisan key:generate

docker compose up -d          # MariaDB, Valkey, Mailpit
php artisan migrate --seed    # seeds a webmaster@crm-solo.test / test1234 login

composer run dev              # PHP server + Horizon + Vite + scheduler + Reverb
```

`php artisan test` runs the suite. `bun run build` builds for production, and
`bun run types` type-checks.

Everything works with no third-party credentials. Integrations stay dormant
until configured, and reports fall back to a deterministic composer when no
AI provider is set up.

## What is worth knowing before reading the code

**`CLAUDE.md` is the real architecture document.** It covers the data models,
the conventions and the pitfalls that cost someone a day. It is written for an
AI coding assistant, which turns out to be the same thing a new human reader
wants: what the models mean, why a decision went the way it did, and which
mistakes the codebase has already made.

**Integrations sit behind registries, not conditionals.** Task sources
(Trello today, GitHub Issues planned), report composers and AI providers each
resolve through a registry, so a second implementation is a class and a
binding rather than a branch through existing code.

**Two things are configuration rather than code**, because they describe one
business rather than the software:

- The report prompt. `resources/prompts/report-narrative.md` ships the output
  contract; an instance can override the whole prompt with
  `php artisan reports:prompt-import <file>`, and `reports:prompt-show` says
  which one resolved.
- The lead funnel. `config/leadgen.php` declares sources, stages, scoring
  factors, tiers and routing. Nothing in the code hardcodes that vocabulary,
  and the shipped numbers are illustrative defaults meant to be replaced.

**Terminal sessions need `ttyd` and `tmux`** (`brew install ttyd tmux`). A
task can open a CLI session in its own git worktree and a dev server beside
it, both served into the page. `docs/development/terminal-sessions.md` has the
details.

**Language.** The interface is English and Polish; new user-facing strings go
into both `lang/en.json` and `lang/pl.json`. Some domain vocabulary stays
Polish where the Polish word is the term of art, "abonament" for a retainer
being the main one.

## Layout

```
app/Services/          business logic, one directory per area
app/Mcp/               MCP server exposing the CRM verbs to an agent
bin/crm                CLI shim; see docs/development/agent-crm-interface.md
resources/js/pages/    Inertia pages, route-matched
resources/prompts/     shipped prompt files
scripts/prod/          per-site production maintenance, gated and idempotent
docs/development/      setup, theming, terminal sessions, database recipes
```

## Status and scope

This is one person's working tool, published because the patterns in it are
worth reading, not because it is a product. There is no upgrade path, no
multi-tenancy beyond an `account_id` column, and decisions are made for a
single operator. Issues and questions are welcome; feature requests will
usually lose to whatever the agency needs that week.

## Licence

MIT, see [LICENSE](LICENSE). Bundled fonts are under the SIL Open Font
Licence, see [resources/fonts/OFL.txt](resources/fonts/OFL.txt).
