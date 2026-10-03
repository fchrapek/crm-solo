# Client reports

Retainer reports, one per client per month, in the client page's Reports tab.
Weekly reports are refused: the retainer hours are a monthly pool.

## Data

`client_reports`: `period_type` (always `month`), `period_start` and
`period_end`, snapshots of `contracted_hours`, `actual_hours`,
`opening_balance_hours`, `rollover_cap_hours` and `currency`, `composer_key`,
`body_markdown` (editable), `status` (`draft`, `finalized`, `sent`) and the
matching timestamps.

- **Hours bank**: closing balance = opening + contracted - actual, shown in the
  summary card. `PATCH .../opening-balance` changes the opening balance and
  rewrites the body's billing summary from it (`BillingSummary`, which finds
  the section by the markdown parser's headings).
- Generate and regenerate store the opening balance and cap the body was
  composed from. Regenerate keeps the saved opening balance and carries one in
  from the previous report only with `recalculate_opening_balance`.
- Rounding a month's hours is done on the report's `actual_hours`
  (`reports:generate --hours=`), never by padding a time entry.

`client_report_revisions` is append-only. Every update, regenerate, finalize,
reopen and opening-balance change first snapshots the previous body, status,
hours, opening balance, cap, currency and composer key with the user, through
`ClientReport::recordRevision()` in the same transaction. That history is what
makes "edit any time" safe: there are no editability locks, and deleting a
report deletes its revisions.

**Concurrent edits**: each write locks the row and carries the version the
editor started from (`ClientReport::version()`, a hash of body and opening
balance). A write based on an older version is refused, and regenerate writes
nothing if the report changed while it was composing.

## Composers

`ReportComposerInterface` implementations resolve through
`ReportComposerRegistry`:

- `AiNarrativeComposer` (`ai_narrative`, the default): OpenAI, model
  `OPENAI_REPORT_MODEL` (default `gpt-4o`). It receives the `ReportContext`
  JSON: hours, package fee, locale, baseline markdown, tasks, time-entry notes.
  The system prompt is resolved, not compiled in: the account's settings row
  (`scope=reports`, `data.narrative_prompt`) when set, else
  `resources/prompts/report-narrative.md` (`NarrativePromptResolver`).
  `reports:prompt-import <file>` writes the override, `reports:prompt-show`
  says which one resolves. On a provider failure, or when no prompt can be
  read, it falls back to the structured composer and `composer_key` still
  records `ai_narrative`.
- `StructuredListComposer` (`structured_list`): deterministic, no AI, the same
  shape. Without an API key it is the only one that runs.

## What a report counts

`ReportDataAggregator` counts only time entries linked to tasks with
`is_reportable = true`. An entry counts for the client it was logged for (its
own `client_id`), whatever project its task belongs to now. Recurring baseline work (monitoring, updates) usually
stays non-reportable and is described by the baseline markdown without hours.
A reportable task also appears when its `finished_at` falls in the period
(never `updated_at`, which any edit or sync moves) and no time was logged on it
before the period. Work done in August and only closed on 3 September belongs
to the August report; it is not listed again in September with zero hours.
Task-less time entries are never reportable.

## The fixed shape

Both composers emit, in order:

1. `## Prace rozwojowe` (or "Development work"): a flat bullet list, one bullet
   per reportable task, never merged, never dropped. One or two plain
   sentences each on what was done and what it gives the client; unfinished
   work says so.
2. `clients.report_baseline_markdown` verbatim (it carries its own `###`
   sections).
3. `## Podsumowanie rozliczeniowe` (or "Billing summary"): the only place a
   balance appears.

No hours or overage line comes before the billing summary. Overage is tracked
in the hours bank and never billed, so a cost figure would promise an invoice
that never comes.

## Flow and UI

Reports tab, Generate report, period and composer, `POST
/clients/{client}/reports`, aggregator, composer, a `draft`, then the edit
page: `<MarkdownTextarea>` with Write and Preview, and Save, Regenerate,
Finalize or Reopen, Copy, Print, Delete. The change history at the bottom
expands update and regenerate rows into a line diff (`<MarkdownDiff>`): each
row shows what that edit changed.

`php artisan reports:generate {client} [--period= --hours= --composer=
--locale=]` drafts one from the command line.

Routes: `POST /clients/{client}/reports`, `GET/PUT/DELETE
/clients/{client}/reports/{report}`, `POST
/clients/{client}/reports/{report}/regenerate|finalize|reopen`, `PATCH
/clients/{client}/reports/{report}/opening-balance`, `PUT
/clients/{client}/report-baseline`. `PATCH /tasks/{task}/reportable` exists
but nothing in the UI calls it; the flag is saved through the task form.

Money and hours on the page format through `useFormatters()` in
`resources/js/lib/format.ts`.
