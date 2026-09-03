# CLAUDE.md

## Project Overview

CRM Solo — Laravel 12 (PHP 8.4) / Inertia.js v2 / React 19 / TypeScript strict. Bun package manager. Docker for local infrastructure.

**Styling: CSS Modules (`.module.css`), NOT Tailwind.**

**Theming**: two axes — `appearance` (light/dark/system) and `theme` (`paper` default / `ink` / `brutal` / `bauhaus`), applied as `.dark` + `data-theme` on `<html>`. Themes set primitives only; surfaces derive in `themes.css`. All decorative colour goes through the **tone palette** (`--tone-*`) in `variables.css` — never hardcode a hue in a component; `bun run lint:tokens` fails on it, and also asserts ink stays achromatic. Full contract + contrast-measurement pitfalls: `docs/development/theming.md`.

**Language**: EN + PL. Add new user-facing strings to both `lang/en.json` and `lang/pl.json`.

## Development Commands

```bash
# Infrastructure
docker compose up -d            # MariaDB, Valkey, Mailpit
docker compose down

# Dev (PHP server + Horizon + Vite + Scheduler + Reverb)
composer run dev
bun run dev                     # Vite only

# Build / lint / types
bun run build                   # bun run build:ssr for SSR
bun run lint                    # ESLint --fix
bun run format                  # Prettier
bun run types                   # tsc --noEmit
composer run lint               # Pint

# Backup
php artisan db:backup           # storage/backups/
php artisan db:backup:list
php artisan db:restore [--force]

# Testing
php artisan test
php artisan test tests/Feature/ContactsTest.php
php artisan test --filter=test_name
```

## Architecture

### Backend (Laravel)
- `app/Http/Controllers/` — REST-style (Clients, Contacts, Users, Integrations, Tasks, Projects, TerminalSessions, …)
- `app/Models/` — Eloquent
- `app/Services/` — Business logic (`Integrations/InfaktService.php`, `AI/…`, `TerminalSessionLauncher`, …)
- `app/Console/Commands/` — Artisan commands
- `routes/web.php` — Inertia web routes

### Frontend (React + Inertia)
- `resources/js/pages/` — route-matched Inertia pages
- `resources/js/layouts/` — AppLayout, AppSidebarLayout, AuthLayout
- `resources/js/components/` — reusable components
  - `components/ui/` — shadcn/Radix primitives (CSS Modules)
  - `components/form/` — form-field components with validation
  - `components/terminal-session/` — embedded ttyd iframe for live CLI sessions
- `resources/js/hooks/` — custom hooks
- `resources/js/contexts/` — React context
- `resources/js/routes/` — Wayfinder-generated typed route functions

### Key Patterns
- Inertia.js for client-server — no separate API layer
- Laravel Wayfinder = typed route helpers for the frontend
- i18next for EN/PL in `lang/`
- TypeScript path alias `@/*` → `resources/js/*`
- JSON fields require `'field' => 'array'` cast in `casts()` — see Pitfalls

### Data Models

Shape only; semantics in code. All account-scoped via `account_id` and `resolveRouteBinding()`.

- **Client** — business/individual. Polish fields (NIP, postal). `type` (legal: business/individual), `segment` (`agency`/`smb`/`enterprise`/`individual` — `Client::SEGMENTS`, nullable), `cooperation_type` (`retainer`/`hourly`/`project`/`one_off` — `Client::COOPERATION_TYPES`, nullable), `hourly_rate` (DECIMAL(10,2) nullable, netto) — only for `cooperation_type=hourly` clients; UI input gated by that condition. Retainer clients use `ClientRetainer.overage_hourly_rate` instead (preserves history). `is_pinned`, `lifecycle_stage` (relationship states `active`/`paused`/`churned` — `Client::LIFECYCLE_STAGES`, default `active`; the pre-client funnel lives in Leads since 2026-07-28, historical events may carry retired `prospect`/`offer_sent` slugs). Stage transitions go through `Client::transitionTo($stage, $note, $user)` (DB transaction, single source of truth; auto-writes event on `created`). On create, a "General" private project is auto-created. `currency` (CHAR(3), default `PLN`, pushed to Clockify as `hourlyRate.currency`; workspace currencies managed in Clockify UI, no `POST /currencies` in v1). `report_baseline_markdown` (longtext, nullable) — Zakres bazowy card in the Raporty rail, edited via dialog (`PUT /clients/{client}/report-baseline`); injected verbatim into every report's "Within retainer" section without hours. Offers are PDFs under Dane (category `oferta`), not a client column. `include_in_month_close` (boolean, default false) gates the `/month-close` worklist; auto-set true when `month_close_type` goes null→typed.
- **ClientRetainer** ("positions") — many-per-client billing lines, each optionally `project_id`-scoped. Fields: `label`, `description`, `monthly_hours`, `monthly_fee` (nullable), `overage_hourly_rate`, `invoice_group`, `vat_symbol`, `rollover_cap_hours`, `is_active`, `sort_order`, `currency`, `effective_from` (nullable), `effective_to` (nullable=open), `notes`. **Monetary values are NETTO** (VAT is invoice-level). Rows are independent (no auto-close/reopen). `Client::activeRetainerOn($date)` = highest-`monthly_hours` active row on a date (report contracted-hours snapshot); `activeRetainersOn($date)` = all active rows (collection, ordered `invoice_group`+`sort_order`). `InfaktService` groups invoice payloads by `invoice_group`, billing only rows with `monthly_fee>0`. UI: `<RetainerChip>` pill shows `{total} zł/mc · {N} poz.` → dialog with add/edit + scrollable fixed-height history. Endpoints: `POST/PUT/DELETE /clients/{client}/retainers[/{retainer}]`.
- **ClientLifecycleEvent** — append-only audit row for stage transitions. `from_stage` (nullable — first event), `to_stage`, `note` (TEXT, nullable, multi-line, editable inline in the Activity tab timeline), `user_id` (nullable for system events), `created_at`. Same-stage events with a note are valid (timeline notes) — created via `POST /clients/{client}/lifecycle-events` (the "+ Add log entry" composer on the Activity timeline; rendered without stage chrome). `PATCH /lifecycle-events/{event}/note` edits.
- **ClientDocument** — client-scoped files (signed contracts, RODO/GDPR clauses, sub-processing agreements) stored LOCALLY at `storage/app/private/client-documents/{client_id}/`, account-scoped via the client. Fields: `file_path`, `original_name`, `mime`, `size`, `category` (umowa/rodo/podpowierzenie), `label`. UI: Documents tab on the client edit page (default filter "All"). `ClientDocumentsController` + `Client::documents()`.
- **MonthCloseRun / MonthCloseStep** — per-client month-close checklist, with **site steps repeating per site**. Client fields: `month_close_type` (`maintenance`/`gig`/null=not in close), `report_mode` (`report`/`summary_email`/`none`), `backup_path`, `ssh_config`, `maintenance_invoice_description`. Run: `period` (YYYY-MM, unique per client), `close_type`, `status` (open/completed) — still ONE run per client. Step: `project_id` (nullable — set = site step, null = client-level)/`step_key`/`position`/`state` (pending/done/skipped)/`note`; unique on `(run, project, step_key)`. **`projects.include_in_month_close`** marks which projects are sites (explicit, not inferred — it is what keeps an unreleased rebuild out); `Client::monthCloseSites()` reads them in checklist order. `MonthCloseRun::SITE_STEPS` (db_archived→local_db_import→wp_updates→local_verify→commit_merge→live_deploy, the last optional) seed once per site; `CLIENT_STEPS` (reconcile_log→report→draft_invoice) seed once, and are the whole checklist for `gig`. The `report` step resolves via `report_mode`. **Division of labour**: the human pulls production DBs by hand and does the push + live deploy; the agent files the dump into the vault, imports locally, updates, verifies, commits. **`projects.backup_path`** is the site's dump destination (the folder directly holding `{YYYY}/{YYYYMMDD}`). Per project because vault folder names do not follow CRM project names and the nesting depth varies per client. `month-close:map-backups` proposes the mapping (`--apply` writes), deriving its search folder from already-mapped sites and leaving contended ones to a human. Commands: `client:config {id}` (returns `sites[]` with `ddev_project` read from `.ddev/config.yaml`, NOT the folder name — the two can disagree), `month-close:sync-sites` (flag sites + report repos missing on disk), `month-close:tick {client} {step} {state} [--project=]` (**a bare step key errors when it repeats across sites, listing them** — same ambiguity contract as client/task refs), `infakt:client {ref}`, `infakt:invoices {id}`, `infakt:draft-invoice`. UI page: `/month-close`. Backup paths are per site and written down rather than derived.
- **Lead** — a prospect in one of the two brand funnels, *before* anything is a Client. `pipeline` (`kiwwwi`/`filipchrapek`), `name`, `company`, `email`, `phone`, `source`, `stage`, `score_factors` (JSON, `'array'` cast), `client_id` (nullable — set on convert, keeps channel attribution), `notes`, `captured_at`, soft deletes. **`source` + `pipeline` are immutable after capture** (enforced in `Lead::booted`, not the DB): "no source tag → the channel doesn't exist", and an editable tag would rewrite a channel's measured history. Stage moves go through `Lead::transitionTo()` (mirrors `Client::transitionTo`). See the Lead-gen funnels section.
- **LeadStageEvent** — append-only audit row for lead stage moves (mirror of `ClientLifecycleEvent`). **This table IS the funnel measurement**: the assumed conversion rates in config are eventually replaced by rates computed from these timestamps. `from_stage` is null exactly once per lead — the capture event, which is a *creation*, not a transition.
- **Contact** — belongs to a Client.
- **User** — role: owner/admin/user.
- **Integration** — external service (Infakt, Trello, Clockify) with encrypted API key.
- **Project** — unit of work for a client. `trello_board_id` nullable: null = private (manual tasks, no sync); set = Trello-backed (one-way sync). Manual tasks live on either kind. `HasMany repositories`. Trello settings in `project.settings`: `trello_workspace`, `trello_lists`, `trello_list_mapping`, `trello_priority_labels`, `custom_lanes` (string[] — extra CRM lanes beyond the canonical 5 that Trello lists can be mapped to; defined in `<ListMappingDialog>`, surfaced on `<ProjectKanban>` via the `lanes` prop). Sync preserves user-edited name + description after first seed. `archived_at` (nullable) dismisses a project: hidden from browsing views, row and tasks kept, and on a Trello board it stops sync re-importing it.
- **Repository** — `name`, `local_path`, `remote_url`, `provider` (github/gitlab/bitbucket/local). Used as the cwd for terminal sessions.
- **Task** — belongs to a Project. Manual tasks CRUD-able; Trello-source read-only. Drag on kanban mirrors `agent_lane` → `list_name` (`backlog→Backlog`, `in_progress→Doing`, `in_review→Testing`, `done→Done` + `is_completed`). Fields:
  - **Core**: `source` (`email`/`trello`/`manual` — `email` is historical only; the email pipeline was removed 2026-07-28), `list_name`, `archived_at`, `type` (`general`/`feature`/`bug`), `priority`/`ai_priority`, `is_reviewed`/`review_action`/`review_changes`/`review_note`, `is_reportable` (default false — controls inclusion in client reports), `recurrence_period_days`, `parent_task_id` (FK `ON DELETE SET NULL` + portable `Task::booted` cascade), `issue_url`.
  - **Agent kanban**: `cli` (`claude`/`codex`/null — null = regular task, non-null = appears on agent kanban), `agent_lane`, `session_port` + `session_pid` (live ttyd process tracking).
  - `HasMany attachments`.
- **TaskAttachment** — files attached to tasks. Types `jpg/jpeg/png/gif/webp/svg/pdf/md/txt/doc/docx/sql/gz/zip`, **2 GB cap** (for SQL dumps). Stored at `storage/app/private/task-attachments/{task_id}/`. Surfaced in `CRM_TASK.md` "Attachments" section with absolute paths so agents can `Read` them. `php artisan serve` needs `-d upload_max_filesize=2G -d post_max_size=2G` (already wired into `composer run dev`). Validation uses both `extensions:` (`.sql` has no canonical mime) AND `mimetypes:` (defense vs renamed-content spoofs).
- **TimeEntry** — billable time. `source` (`clockify`/`terminal_session`/`manual`). `terminal_session` rows auto-opened by `TerminalSessionLauncher::launch` and closed on stop (one running per task, `end_time IS NULL`); `manual` rows via "+ Log time" → `<ManualTimeEntryDialog>` (hits `GET /time-entries/running` to warn on conflict). Manual CRUD: `POST /tasks/{task}/time-entries`, `PUT/DELETE /time-entries/{entry}`. `task_id` nullable for raw Clockify pulls. `title` (short, shown in the Time list) vs `description` (detail, shown when the row is opened); both in `<SessionTimeEditDialog>` (whole row opens it). `update` accepts `task_id` to connect a task (backfills project/client); linked task shows a `#id` chip. **Editable session time**: `<SessionTimeEditDialog>`; `TimeEntriesController::update` patches TimeEntry + linked `TaskSession.started_at`/`ended_at` in one transaction. If `clockify_entry_id` present, `UpdateTimeEntryInClockify` PUTs best-effort. `duration_minutes` uncapped (overnight sessions). UI: per-client Activity "Time" sub-tab + per-task header chip with `{{count}} sessions · {{total}}`.

### Lead-gen funnels (`/leads`)

Two brand funnels, one per brand the practice sells under. **`config/leadgen.php` is the contract** (sources, stages, scoring weights, tiers, SLAs), pinned structurally by `tests/Unit/LeadgenConfigTest.php`. **Never hardcode the vocabulary** — it all reads from config, so the funnel model and the code cannot drift.

- **`visitor` is funnel math, not a row stage.** `analytics_only_stages: ['visitor']` bars it from any Lead row: visitors are anonymous, so **`visitor → lead` is measured in GA4, never here**. Since 2026-07-28 both pipelines share ONE stage set (`new → conversation → offer → won`), `entry_stage` is `new` for both, and `entry_stage_overrides` sends `social` straight to `conversation`. `Lead::rowStages()`/`unifiedRowStages()` drive validation AND the single mixed board (brand = filter + chip, `pipeline` stays immutable attribution). Sources trimmed to `www-form/ads/social/referral/outbound/other`; custom sources come from Settings (settings table merged over config at boot).
- **Capture writes `from_stage = NULL → entry_stage`** — a creation, not a transition. A synthetic hop would forge a 100% conversion at the one boundary the CRM never measures, and poison the later swap to measured rates.
- **Scoring derived, never stored.** Only ticked slugs live in `score_factors`; `scoreTotal()`/`tier()`/`tierRouting()` compute from config on read. Tier slugs stay `gold/oak/rowan` but LABEL as Hot/Warm/Cold (Gorący ≥7 / Ciepły 4-6 / Zimny below); labels resolve config → i18n → humanized slug via `useLeadLabel()`, so custom vocabulary never renders raw. Wrong-funnel factors rejected at model + request boundary; a retired factor stops counting instead of throwing. Tier drives routing, not just colour.
- **Tier filter resolves in PHP** — tier is derived, not a column. Fine at the scale a solo funnel runs at; if outgrown, materialise a tier column rather than a JSON expression in WHERE.
- **`PATCH /leads/{lead}/stage` returns JSON, not a redirect** — the board uses `fetch()`, which follows a 302 with the same method → PATCH hits a GET-only route → 405 → card rolls back though the move committed. Same as `tasks/agent-lane`.
- **Convert-to-client** only at `Lead::wonStage()` (last row stage, derived). Keeps `client_id` so "which channel produced this client" stays answerable.
- Routes: `resource('leads')` except `show`, + `PATCH /leads/{lead}/stage`, `POST /leads/{lead}/convert`, `PUT /leads/{lead}/restore`.

### Client Reports

Retainer reports per client per period (week or month). Live in the **Reports** tab on the client edit page, between Activity and Contacts.

**Data**:
- `client_reports`: `period_type`, `period_start`/`period_end`, `contracted_hours` / `actual_hours` / `opening_balance_hours` / `currency` (snapshotted; hours-bank closing = opening + contracted − actual, in the summary card; opening editable via `PATCH .../opening-balance`), `composer_key`, `body_markdown` (editable), `status` (`draft`/`finalized`/`sent`), `generated_at`/`finalized_at`/`sent_at`.
- `client_report_revisions` (append-only audit): every `update` / `regenerate` / `finalize` / `reopen` snapshots the prior `body_markdown` + `status` + `contracted_hours` + `actual_hours` + `currency` + `composer_key` + `user_id` + `created_at` BEFORE applying the mutation. Atomic via `DB::transaction` in `ClientReport::recordRevision()`. The audit trail is what makes "edit anytime" safe — there are no editability locks; deleted reports take their revisions with them (cascade).

**Pluggable composer**: `App\Services\Reports\ReportComposerInterface` + `ReportComposerRegistry` mirror the Executor pattern. v1 ships two:
- `AiNarrativeComposer` (key `ai_narrative`, default) — OpenAI gpt-4o (`OPENAI_REPORT_MODEL` env). Receives full `ReportContext` JSON: actual/contracted/overage hours, package fee, locale, baseline markdown (verbatim injection only), tasks, time-entry notes. Never full offer/markdown files as context — package terms come from the retainer fields. Falls back to `StructuredListComposer` on provider failure or empty response, so the user always lands on an editable draft.
- `StructuredListComposer` (key `structured_list`) — deterministic no-AI fallback. Hours line + tasks grouped by project (h3) + verbatim baseline injection. Same overage math. **Hours line suppressed** when `actual_hours == 0` AND a baseline is set — the baseline IS the deliverable; a "0h" line would misrepresent value.

**Aggregator filtering**: `ReportDataAggregator` only counts time entries linked to `tasks.is_reportable = true`. Recurring baseline tasks (monitoring/updates/testing/infra) typically stay `is_reportable=false`; their scope ships via the baseline markdown without hours. Ad-hoc / project work flips true and shows with hours. Task-less time entries (raw Clockify pulls, idle terminal sessions) are never reportable in v1.

**Report shape is fixed** (2026-08-04, revised 2026-09-03): **no hours or overage line before the billing summary** (overage is tracked in the bank, never billed, so a cost figure promises an invoice that never arrives). Opens with `## Prace rozwojowe`, a flat bullet list, **one bullet per reportable task, never merged, never dropped** (the AI composer silently omitted an 11h item on 2026-09-03). Each bullet is one or two plain sentences on what was done and what it gives the client; unfinished work says so. Then `report_baseline_markdown` verbatim (four `###` sections, edited in the DB in one place), then `## Podsumowanie rozliczeniowe`, the only place a balance appears. Encoded in the `month-close-site` skill and the `AiNarrativeComposer` prompt; `StructuredListComposer` still emits the old hours line and h3 grouping.

**Generate flow**: Reports tab → **Generate report** → period + composer picker → `POST /clients/{client}/reports` → aggregator → composer → `status='draft'` row → redirect to the edit page.

**Edit page**: markdown editor (existing `<MarkdownTextarea>` with Write/Preview tabs) + always-visible action row (Save / Regenerate / Finalize ↔ Reopen as draft / Copy / Print / Delete). **Change history** collapsible at the bottom; `update` and `regenerate` rows expand inline to show a unified line-by-line diff via `<MarkdownDiff>` (`resources/js/components/markdown-diff/`, powered by `diff@9` / jsdiff with context-collapse at 2 lines). The "after" side of each diff = the body BEFORE the next-newer revision (or the current body for the newest revision) so each row answers "what did THIS edit change?"

**Reusable primitives added**:
- `<MarkdownSection>` is gone. Reach for `<MarkdownTextarea>` in a Dialog for per-record markdown fields.
- `<MarkdownDiff>` — line-by-line diff renderer with green/red rows + "…" gap marker for long unchanged regions.
- `lib/format.ts` `useFormatters()` hook — locale-aware money + hours + per-period formatting (`Intl.NumberFormat`); single source of truth for currency display so a future settings page only touches one file.

**Routes**: `POST/PUT/DELETE /clients/{client}/reports[/{report}]`, `POST /reports/{report}/regenerate`, `POST /reports/{report}/finalize`, `POST /reports/{report}/reopen`, `PATCH /tasks/{task}/reportable`.

### Email pipeline + CRM chat — REMOVED 2026-07-28

Gmail sync, email-to-task, sentiment, the `/emails` pages and the `/crm-chat` agent are gone. Historical rows keep `tasks.source = 'email'` and the legacy review columns; `projects.is_inbox` and `Project::inboxFor()` remain (read by `DeleteProjectsCommand`).

### Terminal Sessions (the agent task surface)

Per-task work runs in a **tmux + ttyd-served CLI session** inside the CRM — the CLI (claude / codex) does the work interactively. Full details in `docs/development/terminal-sessions.md`.

Quick reference:
- **Setup**: `brew install ttyd tmux`.
- **`Task.cli`** = `claude` | `codex` | NULL. Non-null → appears on agent kanban with Start Session button.
- **Session modes** (`tasks.session_mode`): `in_repo` (default — branch in main repo, refuses dirty tree + per-repo mutex) or `worktree` (isolated `.worktrees/task-{id}/`, allows parallel sessions).
- **`TerminalSessionLauncher`** writes `CRM_TASK.md` + spawns `ttyd → tmux → CLI` detached via `nohup` (NOT `setsid` — macOS lacks it). tmux wrapping is load-bearing for iframe-remount survival.
- **End Session is non-destructive**: kills ttyd + closes TimeEntry/TaskSession row, leaves tmux alive so Resume works. **Kill Session** is the destructive variant.
- **Branch picker** required on every Start (`<StartSessionDialog>` + `base_branch` validated). Same dialog also picks **CLI model** per-launch (`cli_model`, nullable) → interpolated as `claude --model <id>` / `codex -m <id>`. Presets in `Task::CLI_MODELS_CLAUDE` / `CLI_MODELS_CODEX`; not persisted. Resume doesn't re-evaluate the CLI invocation (tmux holds the running process), so the model picked at first launch carries through.
- **Live notifications**: claude `Notification` hook → `POST /api/session-events/{token}` → `SessionAttention` Reverb broadcast → toast + kanban dot. Codex has no hook system (silent).
- **Session history** (`task_sessions` table): durable audit log; `Task.session_*` columns are the live pointer. FKs use `unsignedInteger` to match legacy parent id type.
- **CLI invocation**: `claude --append-system-prompt-file CRM_TASK.md` vs `codex "$(cat CRM_TASK.md)"`.
- **tmux session**: `crm-task-{id}`, shared across End→Resume lifecycle, status bar off.

### Project Kanban + Trello list mapping

Each project's **5-lane canonical kanban** (Backlog / To-Do / Doing / Testing / Done — same surface for private + Trello-backed projects) lives on the per-project page `GET /clients/{client}/projects/{project}` (`ProjectBoardController`), with a Board | List pill toggle (list = `<TaskRow>` stack). The client Work tab only lists projects as compact `<ProjectRow>`s inside the Projects card. `<ProjectKanban>` wraps the shared `<KanbanBoard>` primitive (same primitive powers `<AgentKanban>`). Drag updates `list_name` via `PATCH /tasks/{task}/list-name` — manual tasks only (Trello-source drag disabled). Archive UI: "Show archived (N)" toggle reveals with strikethrough + drag disabled.

**Trello list mapping**: `project.settings.trello_list_mapping` = `<trello_list_id>: <CRM lane>`, keyed on **list ID not name** (rename-safe). `TrelloService::syncBoard` writes the resolved lane into `task.list_name` and also stores the source `trello_list_id` on the task (column added 2026-05-23 so mapping changes can be applied retroactively without re-fetching Trello). `TrelloListMapper::guess()` fuzzy-matches names on first sync; unmapped → Backlog. Manual override via `<ListMappingDialog>` → `PUT /projects/{project}/trello-list-mapping`, which **bulk-updates existing tasks' `list_name` immediately** based on their stored `trello_list_id` (no Trello roundtrip required — the user no longer has to wait for the next sync to see lanes redistribute). Mapping values are validated against canonical lanes + `project.settings.custom_lanes`; the dialog's "Add lane" UI lets users define client-specific stages on the fly.

**Kanban layout**: `<KanbanBoard>` primitive uses `grid-auto-flow: column` + `grid-auto-columns: minmax(220px, 280px)` + `overflow-x: auto`, so adding custom lanes scrolls horizontally inside the board instead of squishing the canonical five below readable width. The primitive only scrolls correctly if its parent flex chain allows shrinking — `<SidebarInset>` carries `min-width: 0` for this reason; if you add another wide-content surface (data tables, code blocks), make sure its flex ancestors keep that invariant.

### Agent Kanban

Per-project + per-client view of CLI-configured tasks (where `task.cli IS NOT NULL`). Per-project: `GET /clients/{client}/projects/{project}/agent-board`. Per-client: rendered directly at the top of the Work tab (full-bleed, only when agent tasks exist — no toggle). Both share `<AgentKanban>`. Drag via `@dnd-kit/core` with optimistic update + `PATCH /tasks/{task}/agent-lane`. Lane PATCH mirrors to `list_name` + `is_completed`. Each card's primary action is "Start session" (or "Resume session" when already in_progress) — clicking navigates to `/tasks/{id}` where the embedded terminal renders.

### Task view (`/tasks/{id}`)

Single-column page. Top: breadcrumb (client > project), title + chips (lane / cli / session branch / parent), CLI picker (`PATCH /tasks/{task}/cli`) + Start/Resume Session button + Copy-merge-command button. When a session is live, the `<TerminalSession>` iframe renders below the title. Below that: markdown-rendered description, attachments list, child tasks list.

### Task preview (per-project dev server in the worktree)

Per-task dev server served through ttyd, alongside the agent terminal. Full details in `docs/development/terminal-sessions.md`.

Quick reference:
- **Config** (project edit dialog): `projects.preview_command` (`ddev start`, `bun run dev`, …), `preview_working_dir`, `preview_url`. Stack-agnostic — anything runnable from a directory.
- **`ProjectPreviewLauncher`** mirrors `TerminalSessionLauncher` (tmux+ttyd) with its own session prefix (`crm-preview-{id}`), so agent + preview terminals coexist on one task. `task_previews` tracks pid/port/command/lifecycle.
- **Per-project mutex**: one preview per project (DDEV is a singleton, Node servers race ports). Conflict → `409 {code: 'project_busy'}` → `<ConfirmDialog>` re-POSTs `force=true`.
- **Worktree must exist** (`worktree_missing`) — start a session first; the button hides until then.
- **Stop Preview** kills ttyd + tmux. DDEV keeps serving (detached containers) — `ddev stop` from the project root to swap back.
- **WP gotcha**: WordPress auto-updates itself INTO the worktree (1000+ file diff). Set `AUTOMATIC_UPDATER_DISABLED` + `WP_AUTO_UPDATE_CORE` in `wp-config.php`. Recovery + Vite 5180 rationale in the doc.

### Task Triage

There is no separate review/triage page. Manual tasks land approved (`is_reviewed=true`); users adjust priority / project / name directly on the task page (`/tasks/{id}`) or via the kanban. Dashboard "Need your attention" = `priority=high` OR (`priority IS NULL` AND `ai_priority=high`); it is the only attention surface (the client Work tab filter was cut 2026-07-31). Legacy review columns (`review_action`, `review_changes`, `review_note`, `reviewed_at`, `rejected_at`) remain for historical rows; no new code writes them.

### Integrations
- **Infakt** (Polish invoicing). `php artisan infakt:sync-clients`. Offset pagination. Names from `company_name` OR `first_name`+`last_name`.
- **Trello**. `php artisan trello:sync --force --sync`. Per-project connection only. **READ-ONLY direction**: data flows Trello → CRM. The only CRM → Trello writes are board/list/label creation during onboarding (`TrelloOnboardingService::attachBoard`). **Sync is opt-in**: `syncAllBoards()` skips any board with no project row, and any whose project is archived. Browse and adopt with `php artisan trello:adopt` (no args = list every visible board and its CRM status).
- **Clockify**. `php artisan clockify:sync --force`.

**Trello connect flow** — `<ConnectTrelloDialog>` (`POST /projects/{project}/connect-trello` keyed on `mode`): **create** → fresh board with default lists + priority labels; **link** → attach existing board via lazy picker. Orphan projects (no client_id) are silently adopted — a missing client never blocks a sync, only a missing or archived project does. **Disconnect** (`POST /projects/{project}/disconnect-trello`) nulls `trello_board_id` + `trello_url`, strips Trello settings, keeps existing `source='trello'` tasks as history.

### Infrastructure (Docker)
- MariaDB 11.4 (3306), Valkey (6379), Mailpit (SMTP 1025 / UI 8025). Search boxes use plain LIKE.
- **Queues**: Laravel Horizon. `supervisor-1` (`default` queue, 60s timeout, 128MB) handles most jobs. `supervisor-long-running` (`long-running` queue, 1800s timeout, 256MB, tries=1) exists for long jobs — a job opts in via `$this->onQueue('long-running')` in its constructor. Without the dedicated supervisor, supervisor-1's 60s timeout kills mid-job and Horizon retries from scratch (ends in MaxAttemptsExceeded). Any future long-running job should opt into that queue. Local dev: `QUEUE_CONNECTION=redis` + Horizon (mirrors prod; `composer run dev` uses this).
- **WebSockets**: Laravel Reverb on port **8081** (env `REVERB_PORT=8081` + `REVERB_SERVER_PORT=8081` — moved off the default 8080 because a local DDEV router commonly holds 8080). Events: `SyncCompleted` (`.reverb.completed`), `SessionAttention` (`.reverb.session.attention` on `reverb.session.{taskId}`). All events use plain `new Channel(...)` (public, no auth) — frontend MUST subscribe with `useEchoPublic` from `@laravel/echo-react`, NOT the default `useEcho` (private, fires `POST /broadcasting/auth` which returns 403 for unregistered channels → subscription silently fails → no events delivered).
- **Vite dev server** on port **5180** (`vite.config.ts` `server.port: 5180, strictPort: true`) instead of the default 5173 — WP client theme Vite servers (Sage, wp-solo) default to 5173, so the CRM gets out of their way to keep task-preview DDEV alongside the CRM's own dev possible without port collisions.
- **Logging**: `single` + `errors` (warning+, daily rotation, 30 days) at `storage/logs/errors-YYYY-MM-DD.log`. Per-session ttyd logs at `storage/logs/ttyd-task-{id}.log`.

**Prod**: FrankenPHP + Octane; Docker + GitHub Actions + Cloudflare Tunnels. Only hosted instance = demo.crm-solo.com (`DEMO_MODE`, nightly reseed; `git push origin develop:demo` deploys). The demo dashboard exposes an interactive crm prompt: `POST /demo/cli` runs a closed whitelist of crm verbs in-process via `Artisan::call()` — never a shell (a closed whitelist, never a shell). Login is Cloudflare **Turnstile**-gated wherever `TURNSTILE_SECRET` is set (`app/Rules/Turnstile.php`, implicit rule in `LoginRequest`, fail-closed; sitekey is public with a config default; phpunit.xml pins the secret empty so tests never inherit the dev machine's gate).

## Common Pitfalls

### JSON fields require an array cast
```php
protected function casts(): array
{
    return ['external_ids' => 'array'];
}
```
Without this, saving arrays causes "Array to string conversion".

### Mass assignment
All models use explicit `$fillable` arrays — never `$guarded = []` or `Model::unguard()`. Route model binding on Client, Contact, Task is scoped by `account_id` via `resolveRouteBinding()`.

### Restart Horizon after worker code changes
Workers preload code; web doesn't. Any diff that touches code running inside the worker (jobs, services they call, MODELS-style constants) requires `composer run dev` restart. Loudly flag this.

### updateOrCreate + SoftDeletes silently fails forever
On a `SoftDeletes` model with a unique natural key (e.g. `(account_id, external_id)`), `Model::updateOrCreate([...natural key...], $data)` respects the soft-delete scope, so a previously-soft-deleted row is invisible to the lookup — Eloquent falls through to INSERT, which the DB-level unique index rejects because the soft-deleted row still occupies the slot. Every subsequent sync silently fails on that record forever. Right pattern: look up `withTrashed()` first; if found trashed, `restore()` + `fill()`.

### Accessor columns can't go in ->select()
`Client::name`, etc. are Eloquent accessors (`getXAttribute()`), not DB columns. Adding them to `->select([...])` throws `Column not found`. Include the BACKING columns the accessor reads and let Eloquent compute the accessor lazily.

### Eloquent stores a Carbon's wall-clock, not its instant
Assigning a non-UTC Carbon to a datetime attribute saves the wall-clock string *as if it were UTC*, silently shifting the row by the offset. Always `->setTimezone(config('app.timezone'))` before saving. Storage is UTC (`app.timezone`); `config('app.display_timezone')` (`APP_DISPLAY_TIMEZONE`, default `UTC`) is the human-facing zone that server-side surfaces parse bare times in and print back — `time:log --end` is the first consumer, so any new CLI/report surface taking a `"15:30"` should read it rather than assume UTC. The browser is unaffected (it sends absolute ISO instants). Recipes: `docs/development/quick-db-ops.md`.

### When syncing from external APIs
Check pagination type (offset vs page-based). Handle multiple name formats (company vs individual). Confirm before destructive operations. Test with real data — mocks miss edge cases.

### When creating new pages
CSS Modules (`.module.css`) not Tailwind. Add translations to both `lang/en.json` + `lang/pl.json`. Follow existing structure in `resources/js/pages/`.

### Reuse shared components — HARD RULE
Before building any UI element, check `components/` + `components/ui/` for the existing one and use it: pagination = `<InertiaPagination>` (clients, contacts, client Aktywność — it takes `only`/`preserveState`/`preserveScroll` for lists inside tabbed pages), list views = the Leads-style table anatomy, cards = `<SectionCard>`, dialogs = the themed Dialog, info copy = `<InfoHint>`, markdown editing = `<MarkdownTextarea>`, boards = `<KanbanBoard>`. The CRM's views are templatized: when the shared component lacks a capability, the answer is almost always to extend it with 1-2 props (a custom Prev/Next pager on the client Aktywność tab is the mistake this rule exists to prevent; replaced 2026-07-31). If a need genuinely looks like a NEW kind of component rather than a variant, decide consciously or ask - don't quietly fork.

### Archive vs delete
- `archived_at` hides from default kanban views, keeps the row, restorable. `Task::scopeOnAgentBoard` and `<ProjectKanban>` filter archived by default.
- `source='trello'` mirrors `card.closed`; `PATCH tasks/{task}/archived` refuses Trello-source — archive on Trello.
- `source='manual'` toggles via per-card menu.
- **`projects.archived_at`** is the same idea one level up, and on a Trello-backed project it is a *tombstone*: sync skips the board, so archiving is the only dismissal that survives the next run (deleting the project just lets sync recreate it). `php artisan projects:archive [ids…|--orphans] [--restore]`.

### Inertia partial reloads + shared flash
On a page that polls via `router.reload({ only: [...] })`, default Inertia **skips shared closures** in the partial response. Without intervention, the React `flash` prop stays frozen and every poll re-fires the same toast. Wrap the flash closure in `Inertia::always(fn () => [...])` (`HandleInertiaRequests::share`) so it runs on every response — combined with `session()->pull()` (consume on first read), lifecycle is: redirect carries flash → toast fires once → subsequent partials return null → state cleared.

### Pint and `final` classes
Pint auto-re-finals classes. For testable services, **extract an interface and bind it** — don't try to remove `final`. `TerminalSessionLauncher` / `TerminalSessionLauncherInterface` follow this pattern.

### macOS lacks `setsid`
The terminal-session launcher spawns ttyd with plain `nohup ... &` (NOT `setsid nohup`). `setsid` is Linux-only; on macOS, the wrapping `/bin/sh` fails immediately with "command not found" and the captured PID is the dead sh. Kill the ttyd PID directly on stop; ttyd's SIGTERM handler propagates SIGHUP to its child shell.

### Spawned shells must run under `env -i`
`TerminalSessionLauncher` + `ProjectPreviewLauncher` prefix every ttyd spawn with `env -i <allowlist>` via `App\Services\Concerns\SpawnEnvironment::allowlistedPrefix()`. The allowlist is PATH/HOME/USER/SHELL/TERM/LANG/locale/TMPDIR/SSH_AUTH_SOCK + `CLAUDE_CODE_OAUTH_TOKEN`. Anything else from the PHP server's env — most importantly `DB_*`, `REDIS_*`, `MAIL_*`, OAuth keys — must NOT leak through. Without this an agent CLI working on a different Laravel project inherits CRM Solo's database credentials (since `vlucas/phpdotenv` doesn't override existing env vars) and `php artisan migrate` runs against `crm_solo`. That wiped the dev DB on 2026-05-27. Any new spawn path that runs detached on the host MUST go through `SpawnEnvironment::allowlistedPrefix()`; never inherit env wholesale via bare `shell_exec` / `Process::run`.

### Demo data
`php artisan tasks:create --project=... --name=... --description=...` for manual task creation.
`php artisan tasks:cli {task} {claude|codex|null}` to flip a task's CLI mode for testing.

## Agent interface (2026-07-29)

- **`crm` verbs are the agent-facing API** (bin/crm shim on PATH; docs/development/agent-crm-interface.md): `brief`, `today`, `timer-start`/`timer-stop`, `task-done`, `note`, `lead-capture`/`lead-stage`, plus passthrough (`crm time:log …`). All support `--json`; name fragments resolve with ambiguity-lists. The global `~/.claude/CLAUDE.md` carries the cheat-sheet so any agent translates plain language to verbs.
- **Daily-session card** (dashboard): reads `herdr agent list` every 10s, maps agent cwd → clients via `repositories.local_path`, shows dangling timers (`end_time IS NULL`). Attach = ttyd viewport running `herdr` (no tmux — herdr's daemon is the persistence). Web SAPI children don't inherit HOME reliably — `DailySessionService` resolves it via posix and injects it (herdr needs it for its socket).
- **Humanizer content gate**: every content field (notes, descriptions, journal/report text) passes `HumanizedText` cast → `App\Services\Humanizer` on write (em/en dash → hyphen, curly quotes → straight, ellipsis → dots, invisible chars stripped). Backfill: `crm crm:humanize`. Vocabulary rules live at generation (humanizer skill + composer prompt) — never as PHP regex. Lang files are pinned dash-free by HumanizerTest.
- **Client lifecycle** = relationship states `active/paused/churned` only (default active); the funnel lives in Leads. **Client view**: 6 text tabs (Przegląd default · Praca · Raporty · Faktury · Aktywność · Dane) + Ustawienia as a trailing gear icon; inert info pills below the name (stage / retainer / month-close / hours-bank) — editing lives on the adequate surface (stage → Aktywność rail, abonament + month-close → Ustawienia). Layout system: every tab body in a shared 80rem column, every block a `<SectionCard>` (`components/ui/section-card`), main+rail split via global `recordColumns` (pages.css), kanban boards the only full-bleed surfaces.

