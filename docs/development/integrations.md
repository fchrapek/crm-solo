# Integrations

Three providers, configured per account in Ustawienia · Integracje (`/settings/integracje`; the old `/integrations` URLs redirect there): Infakt, Trello and SMSAPI
(`Integration::PROVIDERS`). Credentials are encrypted
([data-models.md](data-models.md#integration)). Setting up the Trello app
itself: [../integrations/trello-setup.md](../integrations/trello-setup.md).

Clockify was removed. Its imported time entries (`source = 'clockify'`,
`clockify_entry_id`) and the `clockify_client_id` / `clockify_project_id`
columns stay as history; a leftover `integrations` row is inert.

## Infakt (Polish invoicing)

- `php artisan infakt:sync-clients` (also the Sync button) seeds clients from
  Infakt. Infakt paginates by offset; a name is `company_name` or
  `first_name` + `last_name`.
- Infakt only seeds. A later run updates a field only while it still equals
  what the sync last wrote there (a per-field baseline in
  `external_ids.infakt_synced`), so CRM edits win. A client deleted in the CRM
  stays deleted. An exact Infakt id decides the match, deleted or not; a NIP
  match never relinks a client already linked elsewhere, and clients the sync
  could not link are reported.
- `infakt:sync-invoices` mirrors invoices (daily at 03:00 when configured).
- Invoices are created as **drafts only**, through `InfaktDrafting`: the
  `infakt:draft-invoice` command, the agent verb `invoice-draft`, the MCP
  tool `invoice_draft` (`write:invoice-drafts`) and the Faktury za miesiąc
  block on a client's Rozliczenia tab ([month-close.md](month-close.md)).
  The unit is the invoice group, named by its positions' projects; each
  group is drafted once a month (`infakt_draft_requests`, unique per client,
  month and group), from the retainer or as a copy (Wystaw podobną). A month
  may mix the two.
- A copy (`--like=last`) starts from the invoice recorded for that group in
  the latest earlier month, read whole with `GET /invoices/{uuid}.json`: lines
  with their discounts, GTU and PKWiU, notes, bank account, split payment,
  signatures, transaction terms and the Dodatkowy podmiot
  (`third_party_addresses`). The buyer comes from `client_id`, so Infakt fills
  it from its client record. For an agent, a single-group client with nothing
  recorded starts from its latest issued VAT invoice; in the app a first copy
  is always a pick. A group with no usable source (none
  recorded, a correction, advance (`ZAL`), final or draft invoice, or its
  projects changed since) comes back `needs_source` while the other groups
  draft; the owner picks the invoice once (`--project=<project>
  --like=<number>`, or Wybierz fakturę in the app) and later months follow
  it. The row records `source`, `source_invoice_uuid`,
  `source_invoice_number` and `project_ids`. Copies made before 2026-11-03
  sat in group 0; the migration moved one to its group only when that month
  had a single fee-bearing group and nothing recorded there. While a month
  still holds a group 0 copy (not failed), nothing is sent for that month,
  failed groups included (outcome `blocked`, checked again inside the drafting
  lock), until the row is resolved by hand. A client with no fee-bearing
  position is refused on both paths.
- `postDraftInvoice` is the only Infakt write. `assertDraftOnly` walks the
  payload by path: every object (`DRAFT_INVOICE_FIELDS`, `DRAFT_LINE_FIELDS`,
  `DRAFT_ADDRESS_FIELDS`, `DRAFT_TERMS_FIELDS`, `DRAFT_DEAL_FIELDS`) allows
  only its own keys, each value must have its type, lists must be lists, and a
  contract or order is the only place a `number` may appear. A key in
  `DRAFT_FORBIDDEN_FIELDS` (id, uuid, number, status, kind, paid and
  left-to-pay amounts, totals, KSeF, links, timestamps, line ids) anywhere else
  is named as forbidden, so an unknown field from a later API version is
  refused before the POST. `DRAFT_REPLACED_FIELDS` (the
  dates, `client_id`, the buyer snapshot) are set fresh. Nothing in the CRM
  issues, sends or marks an invoice paid. Field names follow the v3 docs
  (Infakt API docs, Przychody, Faktury VAT); the test fixture
  (`tests/Fixtures/infakt/`) is built from them and still needs one real GET
  to confirm it. Infakt's invoice endpoint is asynchronous; a created or
  already drafted draft is read back once into the `invoices` mirror, which
  keeps `external_uuid` so draft rows join it. Run `infakt:sync-invoices` right
  after the deploy that adds `external_uuid`: until a full sync it is empty,
  and the Rozliczenia block shows no amount and no source for a group.
- The Rozliczenia block reads the CRM only, except that a draft Infakt was
  still building when its run ended is confirmed (a status GET) and mirrored
  when the block loads. A row shows an amount only from the mirror (the draft,
  or the source it copies), never the retainer fee. Every undrafted group
  offers Wybierz fakturę; Kopiuj fakturę only while its source looks usable.
  A group needs a pick, with the reason in its (i), when the mirror shows the
  source as a draft, a non-VAT invoice, an advance, correction or final
  invoice, or a settlement (`kind` and `parent_external_id` are mirrored), or
  when the last copy attempt for that group and month was refused (kept in the
  cache for 45 days, cleared when the group drafts). A copied contract or order
  keeps only its date and number.

## Trello

Data flows **Trello to CRM**. The only writes to Trello are board, list and
label creation when a project is connected with a new board
(`TrelloOnboardingService`).

**Sync is opt-in per board.** `trello:sync` (scheduled every five minutes as
`trello:sync --force --sync`) touches only boards adopted as a project, and
skips archived projects. `php artisan trello:adopt` with no argument lists
every board the token can see and whether the CRM has it; with a board id it
adopts it. One sync runs per board at a time (a cache lock with a lease), and
a board's work is bounded inside that lease.

**What the CRM owns**: Trello owns a card's title, description (stored exactly
as the card holds it), list and completion; a synced card is read-only in the
CRM. The CRM owns `finished_at` (the owner's tick, see
[day-screens.md](day-screens.md) for the reconciliation rules) and the brief.
A card closed on Trello archives its task; a card deleted on
the board archives its task after a complete fetch. A card moved to another
synced board of the same client takes its task along; moved to another
client's board (or between a client and a client-less board) it gets a new
task there, and the old task stays with its client, time and reports and is
archived like any card that left its board. Time entries are never moved.

**Card details on open**: checklists, comments and files are fetched when an
agent opens the task (`crm task`), not by the board sync, with backoff and a
stop for gone cards. Files land as task attachments once, within the type and
size caps. All card text is untrusted data for agents.

### Skipped columns

Ustawienia · Integracje · Trello · Konfiguruj, "Kolumny pomijane": Trello
column names whose cards stay on the board but are not tasks in the CRM
(the owner's monthly "Raporty", "Urlopy" and the like). Stored as
`integrations.settings.skipped_lists`, matched by name on every synced board
with case and extra spaces ignored (`TrelloListMapper::skippedListIds()`),
archived columns included, since Trello still returns their cards.
The sync archives a card in a skipped column like a closed one and marks it
`tasks.trello_skipped`, so it leaves every open list, search and the agent
verbs, and `Task::scopeWork()` keeps it out of an order client's done list
and progress, the agent's done list and the old client page's project
progress; moved out, it is a task again. A list mapping change never ticks it,
and a board completion there is no work (`Task::isDone()`); a card still
completed when its column stops being skipped gets its finish then. Filing a card there never
touches the owner's tick, so a finished card keeps its `finished_at` and its
report month. Its time entries stay, so hours logged on it still count.
A day pick on it stays, as for any archived task (open in
`docs/roadmap/2026-10-27-task-handling-usability.md`). The checkboxes
offer the column names seen on the account's synced boards
(`projects.settings.trello_lists`); a name no board has now can be typed in.
A change applies on the next sync. Renaming a column on Trello brings its
cards back until the new name is added.

### Connect and disconnect

`<ConnectTrelloDialog>` posts `POST /projects/{project}/connect-trello` with a
`mode`: `create` makes a board with default lists and priority labels, `link`
attaches an existing board. A board whose project has no client is still
synced; only a missing or archived project stops it. `POST
/projects/{project}/disconnect-trello` clears the board id, URL and Trello
settings and keeps the synced tasks as history.

### List mapping

`project.settings.trello_list_mapping` maps a Trello **list id** (not its
name, so renames are safe) to a CRM lane. The sync stores each task's
`trello_list_id` and writes the mapped lane into `list_name`;
`TrelloListMapper::guess()` matches names on first sync, and an unmatched list
goes to Backlog. `<ListMappingDialog>` saves through `PUT
/projects/{project}/trello-list-mapping`, which applies the new mapping to
existing tasks at once from their stored list ids, each under its row lock,
without calling Trello. Lanes are validated against the five canonical ones
plus `settings.custom_lanes`, which the dialog can extend.

## SMSAPI (text messages)

The account's SMSAPI.pl OAuth token (scope SMS only) is the integration's API token. Saving it sends nothing: the account switches in Ustawienia · Wiadomości SMS · Wysyłka ("Wysyłka przez", with a confirm) or with `crm message-sending --provider=smsapi`, both refused until the integration is connected and on, and `SmsapiProvider` then posts each due message to `sms.do` with the message id as `idx`. A refusal is recorded as `SMSAPI <code>: <message>` on the message; no answer is recorded as failed because the text may have gone out. Nothing is resent on its own: "Wyślij ponownie" (Przegląd, Dziennik, /sms, `crm message-resend`) puts the same row back to pending and keeps the failure as `previous_error`; after no answer it asks first. A failed message waits in the client's Czeka na ciebie and in `crm today` until it is sent again or cancelled. `SMSAPI_TEST=1` sends `test=1`: SMSAPI checks token, number, text and the account limit and sends nothing, and the message is stored as `simulated` with SMSAPI's id. A new SMSAPI account sends only to its registration number until it is activated (error 98). Delivery callbacks and a sender name come with the hosted account. No `check_idx`: message ids repeat between installs sharing one SMSAPI account, and the dispatcher's claim already sends a message once.

## Project board

Each project's board lives at `GET /clients/{client}/projects/{project}`
(`ProjectBoardController`): five canonical lanes (Backlog, To-Do, Doing,
Testing, Done) plus custom lanes, for private and Trello-backed projects
alike. Dragging a manual task
calls `PATCH /tasks/{task}/list-name`; synced cards do not drag. "Show
archived" reveals archived tasks, struck through and not draggable.

Since the Solo redesign the page (Figma 770:56830, phones 849:21156) is the Zadania lanes narrowed to the
project beside a 380 px Projekt column: Synchronizuj and Edytuj in its head, Trello, Synchronizacja (dziś
10:42) and this month's hours. While an agent's timer (started with a token) runs on one of the project's
tasks, `project.live_agent` puts the agent line at the end of the trail and the agent chip in the phone nav
bar.

`<KanbanBoard>` lays lanes out as horizontally scrolling grid columns
(`minmax(220px, 280px)`), so extra lanes scroll instead of squeezing. That
only works while every flex ancestor can shrink: `<SidebarInset>` carries
`min-width: 0` for this, and any new wide surface needs the same.
