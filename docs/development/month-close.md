# Month close

The monthly maintenance and billing checklist per client, on `/month-close`.
The per-site run itself is the `month-close-site` agent skill; this page is the
data and command contract it works against.

## Who is in it

- `clients.month_close_type`: `maintenance` (sites under contract), `gig`
  (no site, only the client-level steps) or null (not in the close).
- `clients.include_in_month_close` gates the worklist; it switches on when a
  type is first set.
- `clients.report_mode` (`report`, `summary_email`, `none`) decides what the
  `report` step means. Also `ssh_config` and
  `maintenance_invoice_description` on the client.
- **Sites are explicit**: `projects.include_in_month_close` marks the projects
  that are maintained sites, never inferred, which keeps an unreleased rebuild
  out. `Client::monthCloseSites()` returns them in checklist order.
- `projects.backup_path` is the folder that directly holds a site's
  `{YYYY}/{YYYYMMDD}` dumps. It is per project because backup folder names do
  not follow project names and the nesting varies per client.

## Runs and steps

- `MonthCloseRun`: one per client and `period` (YYYY-MM, unique per client),
  with `close_type` and `status` (`open`, `completed`).
- `MonthCloseStep`: `project_id` (set for a site step, null for a
  client-level step), `step_key`, `position`, `state` (`pending`, `done`,
  `skipped`), `note`; unique on run, project and step key.
- `MonthCloseRun::SITE_STEPS` repeat for every site: `db_archived`,
  `local_db_import` (optional), `wp_updates`, `local_verify`, `commit_merge`,
  `live_deploy` (optional). `CLIENT_STEPS` run once per client:
  `reconcile_log`, `report`, `draft_invoice`; they are the whole checklist for
  a `gig` client.

**Division of labour**: the owner pulls production databases and does the push
and live deploy; the agent files the dump into the backup folder, imports it
locally, updates, verifies and commits. `MonthCloseRun::OWNER_STEPS`
(`live_deploy` and the deliverable, `report` or `summary_email`) are the
owner's; every other step is the agent's. The split is fixed, not per client.

Every move of a step goes through `MonthCloseStep::mark()`, from the page and
from `month-close:tick` / the MCP tool alike. It records `completed_via`
(`web` or `agent`), since an agent acts as its owner's user and
`completed_by` cannot tell the two apart; ticks before 2026-10-28 have none.

## The page

`/month-close?period=YYYY-MM` (Figma: Zamknięcie miesiąca, direction A) lists
every client in the period's close, one row each: progress and who acts next,
the hours to close for a retainer client with no checklist left, or the day it
finished (all steps moved and the hours month closed). The month is the poster;
the trail offers the month before and after, never past the month in progress
nor before June 2026. `/month-close/{client}?period=` is the client's card: the
steps per site, then the client's own, with the hours close and the client's
report mode and SSH alias beside them. Opening a card never starts a close; the
first tick does (`PATCH month-close/{client}/steps`, the same path as an agent's
tick).

## Commands

| Command | Does |
|---|---|
| `client:config {client} [--json]` | The per-client inputs: cohort, SSH, sites with `backup_path`, `repo_path` and `ddev_project` (read from `.ddev/config.yaml`, not the folder name; the two can differ), invoicing. |
| `month-close:tick {client} {step} {state} [--project= --note= --period=] [--json]` | Move one step. `step` may be `list`. A bare site-step key errors and lists the sites when the client has more than one; pass `--project`. The period defaults to the previous month. |
| `month-close:sync-sites [--apply --prune]` | Report projects that look like sites but are not flagged, and flagged sites whose repository is gone. Writes only with `--apply`. |
| `month-close:map-backups [--apply --force --base=]` | Propose each site's `backup_path` from the backup folders, deriving the search folder from sites already mapped; contended folders are left to a human. |
| `infakt:draft-invoice {client} [--period= --dry-run --status= --resend-unconfirmed]` | Create the maintenance invoice in Infakt as a **draft** only. One numbered request per client, month and invoice group (`infakt_draft_requests`), so a rerun resumes instead of drafting twice; an unanswered request blocks its group until checked. |
| `infakt:client {ref}`, `infakt:invoices {client}` | Look up an Infakt client by its Infakt id or UUID; list a CRM client's recent Infakt invoices. |

The sale date is the last day of the billed month. A retainer position that
ends that month must be dated to the first of the next month, or it is
inactive on the sale date and the draft is refused.

## Financial close is separate

"Zamknij miesiąc" (freezing a month in the hours bank) is not a checklist step:
it is not tickable, agents cannot tick it, and `refreshStatusFromSteps()` does
not look at it. Its rules live in
[client-reports.md](client-reports.md#month-settlement-zamknij-miesiąc).
Przegląd's `report_due` and the checklist's `report` step stay on the previous
month whatever is closed.

The page still shows it, under Godziny on each retainer client's card, and on
the list row of a client with no checklist step left
(`MonthCloseWorklist::settlementRows()`): for the selected period it is
closable when that period is the client's active month, reads "zamknięty DD.MM"
once closed, and "najpierw zamknij <miesiąc>" for a later month while an earlier
one is open. The month in progress and months without a retainer get no row.
The button opens the same confirm dialog as Przegląd.

**Invoicing is not frozen by a closure.** `infakt:draft-invoice`
(`InfaktDraftRun`) bills the live month-end fees of the retainer positions;
a closure freezes hours and reports only.

**Allowed live inputs.** Time entries, tasks, agent logging, timers, the
Trello sync and `crm:humanize` keep working on a closed month and never
rewrite its closure; only the month's logged time changes. In the web or
from an agent (`time:log`, `time-edit`, `time-split`), new entries,
deletes and changes to an entry's time, task or billable are refused there
(`period_settled` for agents); its title and description stay editable.

