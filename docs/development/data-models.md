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

**Account settings**: `accounts.settings` is JSON. `settlement_from` (YYYY-MM)
is the first month whose retainer months must be settled; read it only through
`Account::settlementFrom()`, which falls back to the local creation month
([client-reports.md](client-reports.md#month-settlement-zamknij-miesiąc)).

**More than one account** (2026-10-12): one hosted instance can carry other businesses, each its own account. `php artisan accounts:create "<name>" --owner-email= --owner-first-name= --owner-last-name= --password-file=` makes one with its owner (there is no password reset by mail). The first account is the operator's (`Account::operatorId()`): Horizon, `integrations:probe`, the kiwwwi lead pull and the server indicators in Ustawienia belong to it. Local verbs and the stdio MCP carry no credentials, so once a second account exists they act only in the account named by `AGENT_LOCAL_ACCOUNT` and refuse otherwise; `accounts:create` refuses until it is set. The lead vocabulary (custom sources, source and pipeline labels) is read per account through `App\Services\Leads\LeadVocabulary`; pipelines themselves stay in `config/leadgen.php` for every account.

**Modules**: `accounts.settings.features` switches a module off for one account; a missing key means on. Today there is one, `billing` (month close, retainers and the hours bank, reports, revenue, the Rozliczenia tab). Off, its routes answer 404 (`feature:billing` middleware), the shared `auth.user.account.features` prop hides the nav, `month-close:tick` and its tools refuse with `feature_off`, and `brief`, `client:config`, `today` and search leave the billing data out. Read it only through `Account::hasFeature()`.

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
  `Client::generalProject()` is the one selector (read only): the private
  project still named "General", else the oldest private one. TimeLogger,
  `projects:delete --client` protection and the Zlecenie block use it, so a
  renamed catch-all project keeps its role. Projects are never renamed by code.
- **Billing mode** (`App\Services\Clients\ClientBillingMode`, batched with
  `forIds()`): `retainer` when any retainer position row exists (a future-dated
  one too), any closure or settlement event, or a whole-month report with
  `contracted_hours`; otherwise `order`. The header carries it as
  `billing_mode`; in `order` Przegląd and Rozliczenia show the Zlecenie block
  (`ClientOrderSummary`: title from the catch-all project, the short client
  name while it is still "General"; all time by `client_id`; done over total
  tasks under `Task::scopeOpen()`; the latest invoice by issue date, gross from
  `gross_price`, state draft, unpaid or paid) instead of the hours bank, month
  close, report action and abonament. The controller reads the mode once per
  request and an order page never builds the bank, the settlement or the
  ledger. For an order, Czeka na ciebie lists the next dated open task and the
  unpaid invoice due first, Zadania is one open list plus "Zrobione" and the
  client's time (each bounded, `?wszystkie=open|done|time` expands), the
  invoice rail reads gross, and Zlecenie edits the catch-all project
  (`ProjectFormDialog`, Zakres from its description). Dane heads an
  `individual` client "Osoba prywatna", hides NIP and shows the person's own
  phone and e-mail under Kontakt.
- `hourly_rate` (net) applies to `cooperation_type=hourly` clients; retainer
  clients price overage on the retainer position instead.
- `cooperation_type` may be empty. Lista counts those clients under their own
  filter (`cooperation_type=none`, "Bez typu"), so Wszyscy is the sum of the
  type rows.
- `report_baseline_markdown`: the baseline scope, inserted verbatim into every
  report ([client-reports.md](client-reports.md)).
- `include_in_month_close` gates the `/month-close` worklist; setting
  `month_close_type` from empty to a type turns it on.
- `is_pinned` puts the client first on Lista; inside each group the order stays
  running agent, this month's minutes, name, id. `PUT clients/{client}/pin`
  takes `pinned: bool` and sets it (repeating it changes nothing), from the
  row's pin and Przegląd's ••• menu.
- `clockify_client_id` (and `projects.clockify_project_id`) are history from
  the removed Clockify integration.

**Client record**: `/clients/{client}/{tab}` (`pages/clients/show.tsx`) with
Przegląd, Zadania, Dziennik, Rozliczenia and Dane; `/clients/{client}` opens
Przegląd. **Documents and settings** (`/clients/{client}/edit`,
`pages/clients/edit.tsx`) keeps what has no record tab yet: projects (Work), the full report list, report generation and baseline
(Reports), the invoice list and revenue by year, month or the last 12 months
(Invoices), time entry editing, the stage change and note editing (Activity),
documents and the remaining form fields (Data), and month close, SSH, the
invoice line, retainer positions and delete behind the gear (Settings). The old `#overview` anchor
opens Przegląd (`pages/clients/edit.tsx`).

## Custom client fields

An account adds its own fields to clients (`ClientFieldDefinition`: `key`, `label`, `type` text, textarea, select, multiselect, date, datetime or url, `options` as `{key, label}` pairs, `required`, `on_overview`, `archived_at`). Fields marked `on_overview` show on the client's Przegląd and as Klienci columns; every select field is a Klienci filter, next to the assignee (Moi, a user, nobody). Values live in `clients.custom_fields` (JSON keyed by field key; option keys, never labels; datetimes in UTC), and `clients.assigned_user_id` names the responsible user. Neither column is fillable: the one write path is `App\Services\Clients\Fields\ClientFieldWriter`, which validates through `FieldValue`, takes the client row lock, writes several fields at once or none, records each change in `client_field_events` (old and new value with the label and text as they read then) and recomputes the client's scheduled messages in the same transaction. Definitions change only through `ClientFieldDefinitions`: the key and type are fixed, option keys stay once added, archived fields keep their values (shown to agents as archived) but take no new ones. No trade vocabulary lives in code: an account defines its statuses as select fields.

## Text messages

`message_templates` (body with `{client_name}`, `{assignee_name}`, `{field:key}`), `message_rules` (`field_value`: the field takes a value; `date_offset`: some time from a date field, whole days on the wall clock, optional local time; `expire_minutes`) and `outbound_messages`, one row per message, unique per rule, client and occurrence (`event:<field event id>` or `at:<date value>`). `MessageScheduler` changes only `pending` rows: a moved or cleared date supersedes its reminder and a date that comes back revives it, while a cancelled message stays cancelled. `messages:dispatch` (every minute) claims a due row with a guarded update, rechecks expiry, archive, rule, opt-out (`clients.sms_opt_out`; the screens show a pending message of an opted-out client as held, `held` on the row) and phone (E.164, Polish numbers by default), freezes the recipient and text, and sends through the account's provider (`SmsProviderRegistry`, `accounts.settings.messages.provider`; `simulator` sends nothing, `smsapi` uses the account's SMSAPI integration, [integrations.md](integrations.md)). A failure is recorded and never resent on its own; `MessageSettings::resend` ("Wyślij ponownie", `crm message-resend`) puts that row back to pending with `to` and `body` cleared, a new expiry from the rule, `resends` counted and the failure kept as `previous_error`, and the scheduler never withdraws a resent reminder for being past its time. Quiet hours come from `accounts.settings.messages.quiet_hours` (default 20:00 to 08:00 local). With `accounts.settings.messages.ascii` on (off by default: Polish letters stay) `SmsText` spells characters outside the GSM alphabet without diacritics before sending, so a message stays at 160 characters per SMS; the template preview in Ustawienia counts the SMS the same way. In the app: Ustawienia, Pola klienta and Wiadomości SMS; the client's Dane, Przegląd and Dziennik; the queue at `/sms` (from Klienci and Ustawienia).

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

Routes: `POST/PUT/DELETE /clients/{client}/retainers[/{retainer}]`. A change
that would move what leads on the 1st of an elapsed month owed a close is
refused (`RetainerTerms`), as is one that would make a month before the latest
closure owed; `effective_to` is set, moved or cleared only from the current
month's 1st on.

## ClientMonthClosure and ClientMonthSettlementEvent

`client_month_closures`: one row per client and `period` (CHAR(7), unique
together) for a month frozen in the hours bank: `closed_at` (UTC),
`closed_by_user_id` (set null when the user goes), `snapshot` JSON with
`schema_version`. The snapshot is authoritative for the month's figures and
survives the report and the retainer position it was taken from. A reopen
deletes the row; there is no soft delete, because a trashed row would block
the unique key on the next close.

`client_month_settlement_events` is the audit: one row per close or reopen
(`action`), with the `closure_id` it acted on (no foreign key, the closure may
be gone), the snapshot it froze or discarded and `actor_user_id`. The model
throws on update and delete. Both tables belong to the client and carry no
`account_id`; the cascade fires only on a hard client delete, and clients are
soft-deleted, so archiving keeps them; they are reached only through an
account-scoped client.

`client_reports.needs_recalculation`: set on later drafts when a month's
settled figures change; see [client-reports.md](client-reports.md).

## ClientLifecycleEvent

Append-only: `from_stage` (null on the first event), `to_stage`, `note`,
`user_id` (null for system events). A same-stage event with a note is a
timeline note, written by the Activity composer and Dziennik's "+ Wpis"
(`POST /clients/{client}/lifecycle-events`); `PATCH
/lifecycle-events/{event}/note` edits a note. `task_id` (nullable, null on
delete) ties a note to one task (`crm note --task`, MCP `add_note` `task`): it
stays on the client's timeline and also shows on the task page.

## ClientView

When a signed-in user last opened a client's record: `user_id`, `client_id`
(unique together, both cascade on delete), `viewed_at` (UTC). "Nowe" on
Przegląd and Dziennik counts from it (`App\Services\Clients\ClientViews`):
the record page reads the previous `viewed_at`, then writes now, in the same
request, under a per-user, per-client lock, and `viewed_at` never moves
back. Pages opened less than 30 minutes apart are one visit and keep its
starting point (held in the cache, so two tabs agree), so a tab switch or a
saved note does not empty "nowe"; the gap is measured between page opens. Before the first visit "nowe" reaches back to local midnight
seven days ago. Partial reloads, prefetches, agent verbs, MCP tools and the
agent-context endpoint never write a row; a user can only record a view of a
client in their own account. Dziennik's NOWE label marks work rows only, from
the same instant and inclusive boundary as its summary.

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
- `preview_command`, `preview_working_dir`, `preview_url`: retired with the
  in-app task preview; nothing reads them, kept for a later drop migration.
- A `Repository` (`name`, `local_path`, `remote_url`, `provider` of
  `github`, `gitlab`, `bitbucket` or `local`) tells an agent where the
  project's code lives (`crm task`, `client:config`). It is plain data: the
  CRM runs nothing in it.

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
- `cli`, `agent_lane` and `session_*` belong to the retired agent board and
  terminal sessions; nothing reads or writes them, kept for a later drop
  migration (`docs/roadmap/2026-10-07-outside-agents.md`). The same goes for
  the `task_sessions` and `task_previews` tables.
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

### TaskLink

`task_links`: `task_id` (cascade on delete), `url` (http or https, at most
2048), `label` (humanized). Added and removed on the task page under Zasoby, on
a Trello card too (a link is the CRM's; nothing goes to Trello). `crm task`
lists them in `links` with `from: crm`, under `untrusted`, and readiness counts
one as a target.

### Archive vs delete

- `archived_at` hides a task from the default boards and keeps the row;
  `Task::scopeOnAgentBoard` and `<ProjectKanban>` filter archived tasks.
- A manual task is archived from its card menu. `PATCH
  /tasks/{task}/archived` refuses a synced card: archive it on Trello. A card
  closed on Trello archives its task; a card the board no longer returns is
  archived after a complete card fetch and restored if it comes back.
- Deleting a task unlinks its time (`TimeEntryWriter::detachTask`), so a task
  with time in a closed month or a finalized or sent report cannot be deleted,
  only archived; the web offers the archive, `tasks:delete` skips it and
  `projects:delete` (or the web project delete) refuses the whole project.
- `projects.archived_at` is the same one level up, and on a Trello-backed
  project it is a tombstone: the sync skips the board, so archiving is the only
  dismissal that survives the next run (a deleted project would be recreated).
  `php artisan projects:archive [ids...|--orphans] [--restore]`.

## TimeEntry

Billable time. `source` is `manual`; `terminal_session` rows come from the
retired in-app terminal sessions and `clockify` rows are history from the removed integration (mostly task-less, `clockify_entry_id`
kept, edits stay local).

- Start and stop go through `TimeEntry::startFor()` and `stopNow()`; one
  running entry (`end_time` null) per task.
- Manual entries: "+ Log time" (`<ManualTimeEntryDialog>`, which warns about a
  running entry via `GET /time-entries/running`), `POST
  /tasks/{task}/time-entries`, `PUT/DELETE /time-entries/{timeEntry}`.
  `update` can attach a task (project and client follow it).
- `title` is the short line in lists, `description` the detail.
- `duration_minutes` is not capped; overlapping entries from parallel sessions
  are valid.
- Times are stored in UTC; day and month boundaries come from
  `App\Support\LocalCalendar`. Recipes: [quick-db-ops.md](quick-db-ops.md).

## Agent tokens and the audit trail

- `personal_access_tokens` (`AgentToken`): one Sanctum token per agent, bound to a user and its `account_id`; `abilities` (read plus write groups), `expires_at`, `revoked_at`. Revoked tokens stay as rows so audit entries keep a name. Issued, re-scoped and revoked through `App\Services\Agent\AgentTokens`, by the `agent-tokens:*` operator commands and by the owner in Ustawienia · Agenci.
- `agent_audit_events` (`AgentAuditEvent`): append-only, one row per record an agent verb or MCP tool created, updated or deleted, with account, user, token, `via`, `session_id`, `verb`, the target table and id, and `changes.before` for updates and deletes.
- Actor columns on the records themselves: `time_entries.actor_*`, `client_lifecycle_events.actor_*`, `tasks.finished_by_*` / `finished_via` / `finished_session_id`. Null for anything written from the web UI, except a web finish, which sets `finished_by_user_id` to the signed-in person and clears the agent columns a previous agent finish left.
- A screen names an agent by its token (`App\Services\Agent\AgentName`): "codex macbook" is Codex, "claude-code mac" is Claude Code; a write through a local transport has no token and reads as Agent.

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

## Demo and seed data

Seeded data has to be unusable, not merely fictional-looking:

- domains on a reserved TLD, `.test` by convention
- tax ids that fail the Polish NIP checksum
- phone numbers in an unassigned range (`+48 100 ...`)
- company names carrying a fiction marker (`Przykładow*` / `Testow*`), because
  a plausible Polish company name is almost always somebody's
- no real Infakt or Trello identifiers, which are pointers into third-party
  accounts

Tests pin this: `DemoDataIsFictionalTest` (domains, tax ids, the factory path
`migrate --seed` runs), `DemoSeederTest` (the fiction marker),
`NoRealIntegrationIdsTest` (integration id shapes), and `DemoSeederScreensTest`
(the day screens filled on any reset day).
