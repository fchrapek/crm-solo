# Agent ↔ CRM interface

**The contract: agents talk to the CRM through verbs; the CRM watches agents through the daily-session card (herdr states); the terminal is where the human intervenes.** Decided 2026-07-29 — CLI-first because agents are shells on the same machine. The verbs now have **two transports**: the `crm` CLI shim for anything with a shell, and a **local MCP server** (2026-07-31) for chat clients that have none. Both call the same services; neither shells out to the other. A hosted HTTP transport is the remaining phase. See the client-view rework notes (D4) and the MCP server notes.

## The `crm` shim

`bin/crm` symlinked to `/opt/homebrew/bin/crm`. Verbs shortcut to `crm:*` artisan commands; anything else passes through to artisan (`crm time:log …` works).

Safe inside env-i agent sessions **by design**: the stripped environment carries no other project's `DB_*`, so artisan resolved through the shim always loads crm-solo's own `.env`.

## Verbs (all support `--json`)

| Verb | Does |
|---|---|
| `crm brief <client>` | Full client context: status, retainer positions, hours this month vs limit, open tasks, latest report, journal. Client = id or name fragment (ambiguity lists candidates + ids). |
| `crm today` | Cross-client attention list: urgent/overdue tasks, open month-close runs, hot leads in play, running time entries. |
| `crm task-done <id>` | Canonical completion — Done list, completed flag, agent-lane mirror, recurrence spawn. Idempotent. |
| `crm note <client> "<text>"` | Journal entry on the client timeline (same mechanism as the UI composer). |
| `crm lead-capture --name= --source= [--pipeline --email --phone --company --note]` | Capture with full model validation (immutable source, entry stage from config). |
| `crm lead-stage <id> <stage> [--note=]` | Funnel move through the append-only history. |
| `crm time:log …` | Pre-existing; wall-clock times read `APP_DISPLAY_TIMEZONE`. |
| `crm month-close:tick <client> <step> <state> [--project= --note=]` | Move one checklist step. Site steps repeat per site, so a bare key errors and lists the sites when the client runs more than one. `--note` records why, and is not overwritten by a later state change. |
| `crm month-close:sync-sites [--apply --prune]` | Report drift between projects flagged as sites and what is on disk. Proposes; writes with `--apply`. |
| `crm month-close:map-backups [--apply --force --base=]` | Resolve each site's backup folder against the vault. Only offers folders that hold backups; leaves contended ones to a human. |
| `crm trello:adopt [board] [--client= --name=]` | Without a board id, lists every board the Trello token can reach and whether the CRM has it. With one, adopts it as a project. Sync only touches adopted boards, so this is the gate. |
| `crm projects:archive [ids…] [--orphans --restore]` | Dismiss projects: hidden from views, row and tasks kept, and a Trello board stops being re-imported. `--orphans` targets every client-less project at once. Reverse with `--restore`. |
| `crm client:config …`, `crm tasks:create …` | Pre-existing, pass through. `client:config` returns `sites[]` with per-site `backup_path`, `repo_path` and `ddev_project` (read from `.ddev/config.yaml`, not the folder name). |

Conventions: `--json` prints one JSON line for parsing; human output otherwise. Failures exit 1 with an actionable message (unknown source lists valid sources; ambiguous client lists ids).

## MCP server (local stdio)

Same verbs, second transport — for clients that have no shell (Claude Desktop, ChatGPT desktop, and the browser clients once the hosted transport exists). Registered in `routes/ai.php` as `Mcp::local('crm-solo', CrmServer::class)`; the client launches it, so there is no long-running service to babysit. **The server handle is `crm-solo`** — it is what every client config names and what `mcp:start` / `mcp:inspector` take as their argument.

**Architecture**: tools live in `app/Mcp/Tools/`, the server in `app/Mcp/Servers/CrmServer.php`. Tools never call `Artisan::call` — both transports depend on the same classes in `app/Services/Agent/` (`ClientBrief`, `TodayDigest`, `ClientConfigData`, `TimeLogger`, `TimerService`, `MonthCloseTicker`, `CrmEntityResolver`). Writes go through Eloquent models, so the `HumanizedText` punctuation gate applies automatically.

| Verb | MCP tool | Notes |
|---|---|---|
| `crm brief` | `client_brief` | read-only |
| `crm today` | `today` | read-only, no arguments |
| `crm timer-start` / `timer-stop` | `timer_start` / `timer_stop` | omit `entry` when one timer runs |
| `crm time:log` | `log_time` | `end` is local wall-clock |
| `crm task-done` | `task_done` | idempotent |
| `crm note` | `add_note` | attributed to the acting user |
| `crm lead-capture` / `lead-stage` | `lead_capture` / `lead_stage` | invalid slugs come back with the valid ones |
| `crm month-close:tick` | `month_close_tick` | idempotent; returns the checklist. Takes `project` (required when a step repeats across sites) and `note`. |
| — | `month_close_status` | read-only; **never** starts a run |
| `crm client:config` | `client_config` | read-only |

`infakt:draft-invoice` is deliberately CLI-only for now — it writes to an external system.

**Identity**: `AgentIdentityResolver` (bound to `OwnerIdentityResolver`) answers "which account, which user". MCP writes therefore carry real attribution — journal entries and month-close steps record the owner instead of NULL, which is the one behavioural difference from the CLI verbs.

**Ambiguity contract**: a name fragment matching several records returns a JSON error, not prose:

```json
{"error":"ambiguous_reference","entity":"client","needle":"Verb",
 "message":"Ambiguous client \"Verb\" — call again with the numeric id.",
 "candidates":[{"id":3,"name":"Verb Test Co","context":null}]}
```

`not_found` and `invalid_argument` follow the same shape; `invalid_argument` adds context such as `valid_sources` or `valid_stages`.

**Env**: the stdio server is artisan booting the app normally, so it reads crm-solo's own `.env` — the env-i reasoning from the shim section applies unchanged. Two operational notes: anything printed to stdout during boot corrupts the protocol stream (keep `dump()` out of service providers), and config is merged once per process, so changing settings needs a client reconnect to take effect.

Debug any client's view of the server with `php artisan mcp:inspector crm-solo`.

## Connecting a chat client

Setup guide for whoever is doing the connecting, including people who never open a terminal: **[docs/development/connecting-chat-clients.md](./connecting-chat-clients.md)**. It covers Claude Desktop, ChatGPT desktop, Claude Code, what each app can and cannot reach, and the failure modes worth recognising.

The short version for developers:

```bash
# Claude Code
claude mcp add crm-solo -- /opt/homebrew/bin/php /absolute/path/to/artisan mcp:start crm-solo

# ChatGPT desktop + Codex CLI + Codex IDE extension (one shared config)
codex mcp add crm-solo -- /opt/homebrew/bin/php /absolute/path/to/artisan mcp:start crm-solo
```

Claude Desktop has no CLI: add the block by hand to `claude_desktop_config.json` (Settings → Developer → Edit Config).

**Use an absolute path to the `php` binary in every client config.** Apps launched from the Dock or Finder do not inherit a login shell's `PATH`, so a bare `php` fails to spawn with an opaque error. `which php` gives the value to paste.

## Daily-session card (dashboard)

- Reads `herdr agent list` (JSON) every 10s: per-agent status (working/blocked/idle), terminal title, cwd — cwd mapped to CRM clients via `repositories.local_path`.
- **Attach here** spawns a ttyd viewport running `herdr` (no tmux wrapper — herdr's daemon is the persistence). **Close view** kills only the viewport; agents and the native-terminal attach are untouched.
- Config: `DAILY_SESSION_COMMAND` (default `herdr`), `DAILY_SESSION_CWD` (viewport start dir). Card hides itself when the binary is absent.

## Hook recipe (optional, per-project)

A Claude Code hook that closes the loop when an agent stops — add to a project's `.claude/settings.local.json`:

```json
{
    "hooks": {
        "Stop": [
            {
                "hooks": [
                    {
                        "type": "command",
                        "command": "crm note \"<client>\" \"Agent session stopped in $(basename $PWD)\" >/dev/null 2>&1 || true"
                    }
                ]
            }
        ]
    }
}
```

Swap the command for `crm task-done <id>` / `crm time:log …` per workflow. Keep `|| true` — CRM downtime must never block the agent.

## Web-deployment path (planned, not built)

The verb *contract* survives a hosted CRM; the transport changes. With the MCP layer in place the remaining work is narrow and the seams already exist:

- Swap `Mcp::local` for `Mcp::web('/mcp/crm', CrmServer::class)` with auth middleware (Sanctum bearer tokens, or Passport if a client requires OAuth 2.1). The tools are untouched.
- Rebind `AgentIdentityResolver` to derive the account and user from the request token instead of "first account, its owner". Every tool already scopes its lookups by `identity()->account->id`, so multi-tenant scoping arrives with that one binding.
- The `crm` shim becomes a thin HTTP client, and the browser attach needs the Laravel reverse-proxy (audit T3). Status flow (herdr → CRM) inverts to an outbound reporter from the workstation.
