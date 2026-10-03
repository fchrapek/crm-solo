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
locally, updates, verifies and commits.

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
