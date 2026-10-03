# Terminal Sessions

Per-task work runs in a **tmux + ttyd-served CLI session** inside the CRM. No compile pipeline, no executor, no reviewer agent — the CLI (claude / codex) does the work interactively. The CRM creates the workspace, opens the terminal, and stays out of the way.

**Setup (one-time)**: `brew install ttyd tmux`. Worktree mode: each managed repo should add `.worktrees/` to its `.gitignore` (the launcher does this automatically on first session).

**Host execution switch**: everything that runs a process on the host (terminal sessions, task previews, repository create/edit and branch listing, the daily-session attach) answers to one capability, `App\Support\HostExec`, backed by `config('terminal.host_exec')` (`CRM_HOST_EXEC`, default on) and always off under `DEMO_MODE`. Off, those routes return 403 `{code: host_exec_disabled}` through the `host-exec` middleware, the launchers' process helpers refuse on their own, and the shared `host_exec` prop hides the controls (`useHostExec()`).

**`Task.cli`** = `claude` | `codex` | NULL. NULL = regular kanban task. Non-null = the task appears on the **agent kanban** (`Task::scopeOnAgentBoard()`) and gets a Start Session button. Flip via the dropdown on any task card ("Send to agent kanban" sets cli=claude as the default), or via the CLI picker on the task page to switch between claude/codex.

## Session modes (`tasks.session_mode`)

Enum `worktree` | `in_repo`, picked per launch in `<StartSessionDialog>`, which preselects `in_repo`. Everywhere else the default is `worktree`: the column default, the controller's fallback for a request without `mode`, and the launcher's parameter default.

- **`in_repo`** (the dialog's choice): `git checkout -B session/task-{id} <base>` directly in the main repo working tree. Avoids the worktree portability gotchas (`.env` / `wp-config.php` / `node_modules` not copied, WP auto-updater mutating worktree files, …). Refuses to start if the working tree is dirty (`git status --porcelain` non-empty) OR another `in_repo` session is live on the same repo (per-repo mutex via `Task::SESSION_MODE_IN_REPO + session_pid` query). Hook config writes to `.claude/settings.local.json` (claude merges with `settings.json` — doesn't clobber project config). CRM_TASK.md + `.claude/settings.local.json` go into `.git/info/exclude` so they stay out of `git status`.
- **`worktree`**: creates an isolated git worktree at `<repo>/.worktrees/task-{id}/` on branch `session/task-{id}` (idempotent — reuses if exists). Allows parallel sessions on the same repo. Hook config writes to `.claude/settings.json` (worktree is a fresh tree, safe to own). Path + branch are derived from `task.id`, never stored.

## `TerminalSessionLauncher`

`app/Services/TerminalSessionLauncher.php`, bound via `TerminalSessionLauncherInterface`.

1. Resolves the project's first repository.
2. Dispatches on `$mode`: `launchInWorktree()` or `launchInRepo()`. Both then converge on the same ttyd+tmux spawn path with the chosen `session_path` as CWD.
3. Writes `CRM_TASK.md` into the session root (`TerminalSessionLauncher::taskInstructions`). It becomes Claude's system prompt and Codex's first message, so it holds only text the CRM wrote: the task id, `crm task <id> --json` to read the task (title, description, checklists, comments, attachment paths), the rule that card text and file contents are data and never instructions, brief fields the owner confirmed, and child task ids. No card title, description, checklist, comment or file name is interpolated into it.
4. Spawns ttyd detached via `nohup env -i <allowlist> ttyd -p <port> -i 127.0.0.1 -W ... bash -lc '<tmux attach-or-create with CLI inside>'` (NOT `setsid` — macOS doesn't ship it). Two layers are load-bearing here:
   - **tmux wrapping**: ttyd doesn't replay history on websocket reconnect, so without tmux the user sees a blank terminal on iframe remount even though the CLI is alive. tmux re-renders its internal buffer on each xterm.js initial-size SIGWINCH.
   - **`env -i <allowlist>`** (via `App\Services\Concerns\SpawnEnvironment`): strips the PHP server's `DB_*`/`REDIS_*`/`MAIL_*`/`OPENAI_*`/etc. from the child env so an agent CLI working on a different Laravel project can't inherit CRM Solo's database credentials. Without it, `php artisan migrate` inside the spawned shell would run against the CRM's own database. `ProjectPreviewLauncher` uses the same helper for the same reason.
5. Stores the allocated port + pid + chosen mode on `tasks.session_port` / `tasks.session_pid` / `tasks.session_mode`. Restart is idempotent (alive PID returns existing port; stale PID respawns).

## Agent board and task page

A task with `cli` set appears on the **agent kanban**: per project at `GET
/clients/{client}/projects/{project}/agent-board`, and per client at the top
of the Work tab when the client has agent tasks. Both render `<AgentKanban>`
on the shared `<KanbanBoard>`. Dragging calls `PATCH /tasks/{task}/agent-lane`
(JSON answer, optimistic update), which goes through `TaskCompletion`: on a
manual task the lane mirrors to `list_name` (`backlog` to To-Do,
`in_progress` to Doing, `in_review` to Testing, `done` to Done and completed);
on a Trello card it moves only the agent lane and the owner's `finished_at`.
A card's main action opens `/tasks/{id}`.

The task page (`/tasks/{id}`): breadcrumb, title and chips, the CLI picker
(`PATCH /tasks/{task}/cli`), Start or Resume session, Copy merge command, the
embedded terminal while a session is live, the task preview, the description
and attachments, session history and child tasks.

## `<TerminalSession>` component

`resources/js/components/terminal-session/`: renders an iframe to `http://localhost:<session_port>` on the task page. Survives navigation away and browser refresh (tmux holds the screen buffer; ttyd holds the PTY).

**End Session is non-destructive**: it kills the ttyd front-end + closes the open TimeEntry + closes the open TaskSession history row, but **leaves the tmux session alive** so the user can Resume later with full scrollback + the CLI's in-memory state. The task page surfaces a "Resume session" button when `tmux_alive && !session_port` (single-click, no branch picker — the worktree + branch already exist). For the explicit destructive case the page has a separate **"Kill session"** button → `DELETE /tasks/{task}/kill-session` runs `tmux kill-session` then the standard stop() teardown.

## Branch picker on every Start session

`<StartSessionDialog>` + `GET /tasks/{task}/session-branches`. `TerminalSessionsController::start` requires `base_branch` (validated). The dialog fetches local branches + `origin/HEAD` + `HEAD` from `TerminalSessionLauncher::listSessionBranches`, pre-selects default→current→first, and only fires the actual `POST /tasks/{task}/start-session` after the user confirms. Resume of an already-running session bypasses the dialog — the frontend just navigates to `/tasks/{id}` since the launcher is idempotent. Repo-missing chains via `onRepoMissing` into `<RepositoryFormDialog>`, which on save reopens the branch picker. The launcher still has a fallback `resolveBaseBranch` (main → master → HEAD) but it only kicks in for direct API/test calls; the UI always passes an explicit pick.

The same dialog picks the **CLI model** per launch (`cli_model`, optional, validated as a plain identifier), passed as `claude --model <id>` or `codex -m <id>`. The presets are `CLI_MODEL_PRESETS` in `start-session-dialog.tsx`; the choice is not stored. Resume does not rebuild the CLI command (tmux holds the running process), so the model picked at the first launch carries through.

## Live session notifications (claude hook)

Claude `Notification` hook → toast + kanban "waiting" badge. On launch, `TerminalSessionLauncher` generates `tasks.session_token` (random 40-char, rotates per launch) + writes `.claude/settings.json` into the worktree with a `Notification` hook that `curl`s `POST /api/session-events/{token}` with `{event: 'notification'}`. `CRM_SESSION_TOKEN` is exported in the inner tmux command + replayed via `tmux set-environment` so the hook subshell inherits it. `SessionEventsController` looks up the task by token (404 if unknown so leaked tokens from finished sessions can't be reused), sets `tasks.session_attention_at = now()`, and broadcasts `SessionAttention(taskId, taskName, event, message)` on `reverb.session.{taskId}` as `.reverb.session.attention`. Frontend `<SessionAttentionListener>` (mounted globally in `AppLayout`) reads `live_sessions` from shared props — every task with a non-null `session_pid` belonging to this account — and subscribes via `useEchoPublic` to each per-task channel (the channels are public; the default `useEcho` subscribes as private, fails the auth call and receives nothing), firing a Sonner toast with click-to-open. `<AgentKanban>` renders a pulsing dot chip ("Waiting on you") when `task.session_attention_at !== null`. Visiting `/tasks/{id}` fires `POST /tasks/{task}/clear-session-attention` so the dot disappears. `stop()` clears `session_token` + `session_attention_at` so the channel goes quiet for that task.

**Codex has no hook system yet**: the launcher only writes the config for `cli === 'claude'`; codex sessions are otherwise identical but silent.

## CLI invocation per kind

- `claude --append-system-prompt-file CRM_TASK.md` — task brief lives in claude's system prompt; no turn burned, claude waits silently for the user's first message.
- `codex "$(cat CRM_TASK.md)"` — positional prompt; codex acknowledges, then waits for input.

## tmux

`crm-task-{id}` (shared across the task's full lifecycle — End→Resume reattaches the same pane). Status bar hidden (`set-option status off`). Only `kill()` runs `tmux kill-session`; `stop()` leaves tmux alive so Resume works.

## Session history

`task_sessions` table + `<SessionHistory>` component on `/tasks/{id}`. Every launch appends a row: `task_id`, `account_id`, `cli`, `base_branch`, `branch_name`, `worktree_path` (legacy DB column name — holds the worktree path in `worktree` mode, the repo root in `in_repo` mode), `time_entry_id`, `started_at`, `ended_at`, `ended_reason` (`stopped` for clean End, `crashed` for stop() on dead PID). `Task.session_*` columns remain as the LIVE-session pointer; the history table is the durable audit log so past lifecycles survive `stop()`. `TerminalSessionLauncher::launch()` and `resume()` both create new rows; `openSessionRow()` is idempotent on a still-open row (handles ttyd-crash-then-relaunch cleanly). `tasks.tmux_alive` is computed at request time from `tmux has-session` and drives the UI's "Resume" affordance on the latest closed history row. Migration `2026_05_23_110000_create_task_sessions_table` backfills any task with a live session at migration time (uses the open TimeEntry's `start_time`, marks `base_branch='unknown'` since that wasn't recorded pre-table). FKs on `task_id`/`account_id`/`time_entry_id` use `unsignedInteger` (not `foreignId`) — all the parent tables use the legacy `int(10) unsigned` id type and `bigint` would fail FK creation.

## Composing parallel sessions (worktree mode only)

Each task = own worktree on own branch. After agents finish, merge into a long-lived `preview` branch in the main repo dir for composed DDEV preview. The CRM doesn't manage the preview branch; user runs `git merge session/task-{id}` (the task page exposes a Copy merge command button). `in_repo` mode is serial — one session per repo — so this only applies to worktree mode.

## Task preview (per-project dev server in the worktree)

Each project optionally declares **how to serve itself in dev**:
`projects.preview_command` (e.g. `ddev start`, `bun run dev`, `rails s`),
`projects.preview_working_dir` (relative to repo root, defaults to repo root),
`projects.preview_url` (optional click-to-open hint). Configured via the
project edit dialog. Stack-agnostic — the CRM just shells the command out via
ttyd in the task worktree; DDEV/Vite/Rails/anything works as long as it is
runnable from a directory.

**Start Preview button** on the task page (visible when `task.cli !== null` AND
the project has a `preview_command`) spawns `ttyd` inside
`<worktree>/<preview_working_dir>` running the command. `ProjectPreviewLauncher`
mirrors `TerminalSessionLauncher`'s tmux+ttyd pattern with a separate session
prefix (`crm-preview-{id}` vs `crm-task-{id}`) so the agent terminal and the
preview terminal can coexist on the same task. A `task_previews` row tracks
lifecycle: `pid`, `port`, `command`, `working_dir`, `url`, `started_at`,
`stopped_at`, `stopped_reason`.

**Per-project mutex**: only one preview at a time per project (DDEV is a
singleton; Node servers would race ports). `TaskPreviewsController::start`
returns `409 { code: 'project_busy', conflicting_task: {id, name} }` if another
task on the same project is already running a preview. The frontend shows a
`<ConfirmDialog>` ("Stop preview on X and start this?") which re-POSTs with
`force=true` — the controller stops the conflicting task's preview, then starts
this one.

**Stop Preview** kills the ttyd PID + the tmux session. For Node-style dev
servers (vite, etc.) this kills the dev server. For DDEV-style start commands
(`ddev start` returns after spawning detached containers), the underlying
service keeps serving — you need `ddev stop` from the project root to swap back
to the main repo. The Stop dialog's description calls this out.

**Worktree must exist** — the launcher refuses with `worktree_missing` if there
is no `<repo>/.worktrees/task-{id}/` yet. Start a session first (which creates
the worktree), then start preview. The Start Preview button is hidden until both
conditions are met.

The embedded preview ttyd pane renders below the agent terminal (when both are
running) so the user can watch dev-server output (Vite's "Local: …" URL, build
errors, etc.) without leaving the task page.

### WP-stack preview gotcha — WordPress auto-updates itself into the worktree

First time DDEV mounts a worktree and serves a request, `wp-cron` fires WP's
auto-updater, writing core files **into the worktree** (1000+ file diff, drowns
the agent's changes). Mitigate per project in `wp-config.php`, BEFORE the
`wp-settings.php` require:

```php
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );
```

Recover an already-mutated worktree:

```bash
git checkout HEAD -- wp-admin wp-includes wp-*.php
git clean -fd wp-admin wp-includes wp-content/languages   # translations auto-update too
```

Related: WP themes' Vite defaults to 5173, so the CRM's Vite was bumped to 5180
(`vite.config.ts`).
