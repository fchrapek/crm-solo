# Integrations

Two providers, configured per account on `/integrations`: Infakt and Trello
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
- Invoices are created as **drafts only**, through `infakt:draft-invoice`
  ([month-close.md](month-close.md)). Infakt's invoice endpoint is
  asynchronous.

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
[day-screens.md](day-screens.md) for the reconciliation rules), the brief and
the agent lane. A card closed on Trello archives its task; a card deleted on
the board archives its task after a complete fetch. A card moved to another
synced board of the same client takes its task along; moved to another
client's board (or between a client and a client-less board) it gets a new
task there, and the old task stays with its client, time and reports and is
archived like any card that left its board. Time entries are never moved.

**Card details on open**: checklists, comments and files are fetched when an
agent opens the task (`crm task`), not by the board sync, with backoff and a
stop for gone cards. Files land as task attachments once, within the type and
size caps. All card text is untrusted data for agents.

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

## Project board

Each project's board lives at `GET /clients/{client}/projects/{project}`
(`ProjectBoardController`): five canonical lanes (Backlog, To-Do, Doing,
Testing, Done) plus custom lanes, for private and Trello-backed projects
alike, with a Board or List (a table) toggle. The client Work tab lists
projects as `<ProjectRow>`s. `<ProjectKanban>` wraps the shared
`<KanbanBoard>`, which also powers `<AgentKanban>`. Dragging a manual task
calls `PATCH /tasks/{task}/list-name`; synced cards do not drag. "Show
archived" reveals archived tasks, struck through and not draggable.

`<KanbanBoard>` lays lanes out as horizontally scrolling grid columns
(`minmax(220px, 280px)`), so extra lanes scroll instead of squeezing. That
only works while every flex ancestor can shrink: `<SidebarInset>` carries
`min-width: 0` for this, and any new wide surface needs the same.
