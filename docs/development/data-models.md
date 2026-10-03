# Data models

What the models mean, where the code does not say it on its own. Field lists
are in each model's `$fillable` and `casts()`; this page covers the rules
around them. Leads, reports, month close and the Trello sync have their own
pages: [leads.md](leads.md), [client-reports.md](client-reports.md),
[month-close.md](month-close.md), [integrations.md](integrations.md).

## Accounts and scoping

Every business row carries `account_id`. Two mechanisms keep one account out
of another's records, and a new model reachable by URL needs one of them:

- **Route binding**: `resolveRouteBinding()` scopes the lookup to the signed-in
  user's account on `Client`, `Contact`, `Task`, `Lead`, `User`, `DayPick`,
  `MonthCloseRun` and `MonthCloseStep`.
- **Controller checks**: everything else (`Project`, `TimeEntry`,
  `ClientReport`, `ClientDocument`, `ClientRetainer`, ...) is checked in its
  controller against the account of the record or its parent, for example
  `ProjectBoardController` and `TimeEntriesController`.

Agent verbs and MCP tools act inside the resolved identity's account
(`App\Services\Agent\AgentIdentityResolver`); another account's record answers
not found.

**User**: `users.owner` is a boolean. There is no role column and no admin
role; `User::whereRole()` understands `owner` and `user` only.

## Client

A business or an individual (`type`), with Polish fields (NIP in `tax_id`,
postal code). Vocabulary lives in constants: `Client::LIFECYCLE_STAGES`
(`active`, `paused`, `churned`, default `active`), `SEGMENTS`,
`COOPERATION_TYPES`, `MONTH_CLOSE_TYPES`, `REPORT_MODES`.

- **Lifecycle** is the relationship only; the pre-client funnel is Leads. Stage
  moves go through `Client::transitionTo($stage, $note, $user)`, which writes a
  `ClientLifecycleEvent` in the same transaction. Creating a client writes the
  first event (`from_stage` null).
- **General project**: the `created` hook calls `ensureGeneralProject()`, so
  every client (form, lead convert, Infakt sync) gets a private "General"
  project for time logged without a task.
  `php artisan clients:ensure-general-project [--apply]` backfills older rows.
- `hourly_rate` (net) applies to `cooperation_type=hourly` clients; retainer
  clients price overage on the retainer position instead.
- `report_baseline_markdown`: the baseline scope, inserted verbatim into every
  report ([client-reports.md](client-reports.md)).
- `include_in_month_close` gates the `/month-close` worklist; setting
  `month_close_type` from empty to a type turns it on.
- `clockify_client_id` (and `projects.clockify_project_id`) are history from
  the removed Clockify integration.

**Client page tabs**: Overview (default), Work, Reports, Invoices, Activity,
Data, plus Settings behind the gear icon (`pages/clients/edit.tsx`). The pills
under the name only inform: lifecycle stage is edited in the Activity rail,
retainer positions (`<RetainerChip>`) and month close under Settings, contacts
and documents under Data.

## ClientRetainer ("positions")

Many billing lines per client, each optionally scoped to a project. Money is
**net**; VAT is decided per invoice. A position is active on a date when
`effective_from <= date < effective_to` (`effective_to` null means open), so a
position meant to end with a month is dated the first of the next month.
Positions are independent: nothing closes or reopens another.

- `Client::activeRetainerOn($date)`: the active position with the most
  `monthly_hours` (the contracted hours a report snapshots).
- `Client::activeRetainersOn($date)`: all active positions, ordered by
  `invoice_group`, `sort_order`.
- Infakt drafts group positions by `invoice_group` and bill only positions with
  `monthly_fee > 0`.

Routes: `POST/PUT/DELETE /clients/{client}/retainers[/{retainer}]`.

## ClientLifecycleEvent

Append-only: `from_stage` (null on the first event), `to_stage`, `note`,
`user_id` (null for system events). A same-stage event with a note is a
timeline note, written by the Activity composer
(`POST /clients/{client}/lifecycle-events`); `PATCH
/lifecycle-events/{event}/note` edits a note.

## ClientDocument

Client files (contracts, GDPR clauses, offers) on the local disk under
`storage/app/private/client-documents/{client_id}/`. `category` is free text
up to 50 characters on the server; the UI offers `umowa`, `podpowierzenie`,
`rodo`, `oferta`, `inne` (`documents-tab.tsx`). Shown in the Data tab next to
contacts.

## Project and Repository

A project is a unit of work for a client. `trello_board_id` null means a
private project (manual tasks only); set means Trello-backed
([integrations.md](integrations.md)). Manual tasks live on either kind.

- `settings` holds the Trello mapping (`trello_list_mapping`, `custom_lanes`,
  and the board's lists and labels).
- `archived_at` dismisses a project: hidden from browsing views, row and tasks
  kept, and a Trello board stops being re-imported (see Archive below).
- `include_in_month_close` and `backup_path` mark a project as a maintained
  site ([month-close.md](month-close.md)).
- `preview_command`, `preview_working_dir`, `preview_url`: the task preview
  ([terminal-sessions.md](terminal-sessions.md)).
- A `Repository` (`name`, `local_path`, `remote_url`, `provider` of
  `github`, `gitlab`, `bitbucket` or `local`) is the working directory of a
  terminal session; `local_path` also maps agent working directories to
  clients on the `/sesje` card.

## Task

Belongs to a project. Fields worth knowing:

- **A synced card is read-only.** `Task::hasTrelloCard()` (`trello_card_id`
  set) decides, whatever `source` says. Trello owns the card's title,
  description, list and completion; the CRM owns `finished_at` and the brief.
- **Done is two facts.** `is_completed` and `list_name` are the card's status;
  `finished_at` is the owner's tick. Ticking and unticking go through
  `App\Services\Tasks\TaskCompletion` on every surface: it stops the task's
  running timers first, moves a manual task to Done (and spawns the next
  recurring instance), and on a Trello card records only `finished_at`.
- `Task::scopeOpen()` is the one definition of an open task (not finished, not
  completed, not archived, not in Done, on a live project).
- `source`: `manual`, `trello`, or `email` (history from a removed email
  pipeline, together with the `is_reviewed`/`review_*` columns).
- `is_reportable` (default false) puts the task's time in client reports.
- `recurrence_period_days` with `recurrence_predecessor_id`: one finish spawns
  one successor.
- `parent_task_id` (FK `ON DELETE SET NULL`; `Task::booted` also nulls the
  children in PHP, so the rule holds where the FK does not exist).
- `cli` (`claude`, `codex` or null), `agent_lane`, `session_*`: the agent
  board and terminal sessions ([terminal-sessions.md](terminal-sessions.md)).
- A `TaskBrief` (where, done when, constraints, notes) is the CRM's own brief,
  drafted by agents and confirmed field by field by the owner
  ([agent-crm-interface.md](agent-crm-interface.md)).

### TaskAttachment

Uploads, or files pulled from the task's Trello card (`trello_attachment_id`,
capped by `TRELLO_ATTACHMENT_MAX_KB` per file and
`TRELLO_ATTACHMENTS_TASK_MAX_KB` per task). Allowed types are
`App\Support\TaskAttachmentTypes` (images, pdf, md, txt, doc, docx, sql, gz,
zip), checked by extension and by detected MIME type. Stored under
`storage/app/private/task-attachments/{task_id}/`. The upload cap is large for
SQL dumps, which is why `composer run dev` starts PHP with 2G upload limits.
Agents get absolute paths from `crm task <id> --json`.

### Archive vs delete

- `archived_at` hides a task from the default boards and keeps the row;
  `Task::scopeOnAgentBoard` and `<ProjectKanban>` filter archived tasks.
- A manual task is archived from its card menu. `PATCH
  /tasks/{task}/archived` refuses a synced card: archive it on Trello. A card
  closed on Trello archives its task; a card the board no longer returns is
  archived after a complete card fetch and restored if it comes back.
- `projects.archived_at` is the same one level up, and on a Trello-backed
  project it is a tombstone: the sync skips the board, so archiving is the only
  dismissal that survives the next run (a deleted project would be recreated).
  `php artisan projects:archive [ids...|--orphans] [--restore]`.

## TimeEntry

Billable time. `source` is `manual` or `terminal_session`; `clockify` rows are
history from the removed integration (mostly task-less, `clockify_entry_id`
kept, edits stay local).

- Start and stop go through `TimeEntry::startFor()` and `stopNow()`; one
  running entry (`end_time` null) per task.
- A terminal session opens a `terminal_session` entry and closes it on End.
- Manual entries: "+ Log time" (`<ManualTimeEntryDialog>`, which warns about a
  running entry via `GET /time-entries/running`), `POST
  /tasks/{task}/time-entries`, `PUT/DELETE /time-entries/{timeEntry}`.
  `update` can attach a task (project and client follow it) and patches a
  linked `TaskSession`'s start and end in the same transaction.
- `title` is the short line in lists, `description` the detail.
- `duration_minutes` is not capped; overlapping entries from parallel sessions
  are valid.
- Times are stored in UTC; day and month boundaries come from
  `App\Support\LocalCalendar`. Recipes: [quick-db-ops.md](quick-db-ops.md).

## Agent tokens and the audit trail

- `personal_access_tokens` (`AgentToken`): one Sanctum token per agent, bound to a user and its `account_id`; `abilities` (read plus write groups), `expires_at`, `revoked_at`. Revoked tokens stay as rows so audit entries keep a name. Issued and revoked with `agent-tokens:*` (operator commands).
- `agent_audit_events` (`AgentAuditEvent`): append-only, one row per record an agent verb or MCP tool created, updated or deleted, with account, user, token, `via`, `session_id`, `verb`, the target table and id, and `changes.before` for updates and deletes.
- Actor columns on the records themselves: `time_entries.actor_*`, `client_lifecycle_events.actor_*`, `tasks.finished_by_*` / `finished_via` / `finished_session_id`. Null for anything written from the web UI.

Details: `agent-crm-interface.md`, "Audit trail and attribution".

## Invoice

`invoices.net_price` is a raw amount next to its own `currency` column; PLN and USD rows sit side by side with no conversion. Any total groups by currency (`SUM(net_price)` over all rows blends zloty and dollars), and checks `status` first: `sent` and `draft` are not paid. Found on 2026-08-25, when 2025 revenue was first reported as one PLN figure that was really PLN plus USD from the foreign agency clients. Real revenue and profit come from the RZiS import, not from this table.

## Integration

One row per provider in `Integration::PROVIDERS` (`infakt`, `trello`); a row
for any other provider is ignored. `api_key` is encrypted, and so are the
credential keys inside `settings` (`Integration::SECRET_SETTINGS`) through the
`SettingsWithSecrets` cast. A blank credential field on save keeps the stored
value. A credential encrypted under another `APP_KEY` reads as missing, is kept
unchanged, and makes the services refuse to run
(`Integration::unreadableSecrets()`). Disconnect clears the credentials.

## Content fields and the humanizer gate

Prose fields (client notes and baseline, lifecycle, lead and lead-stage
notes, time-entry titles and descriptions, report bodies, task briefs, manual
task descriptions) go through the `HumanizedText` cast, which runs
`App\Services\Humanizer::clean()` on write. It is punctuation only: em and en
dashes become hyphens, curly quotes straight ones, the ellipsis character three
dots, invisible characters are dropped. It never touches code blocks, inline
code, link destinations or any token with a slash in it (URLs, paths), and it
keeps line breaks. A Trello card's description is stored exactly as the card
holds it (`TaskDescription` cast). Word-level rules belong at generation (the
report prompt, the humanizer skill), never in PHP regex.
`crm crm:humanize` lists what a backfill would change; `--write` applies it
(report bodies through a revision). `HumanizerTest` keeps the lang files free
of long dashes.
