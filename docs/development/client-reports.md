# Client reports

Retainer reports, one per client per month, in the client page's Reports tab.
Weekly and partial reports are refused: the retainer hours are a monthly pool,
so a new report starts on the 1st and ends on the last day of that month. A
month that already has a report gets no second one: `POST .../reports` opens
the existing report (Regenerate refreshes its figures there) and
`reports:generate` fails naming it.

## Data

`client_reports`: `period_type` (always `month`), `period_start` and
`period_end`, snapshots of `contracted_hours`, `actual_hours`,
`opening_balance_hours`, `rollover_cap_hours` and `currency`, `composer_key`,
`body_markdown` (editable), `status` (`draft`, `finalized`, `sent`) and the
matching timestamps, and `needs_recalculation` (see Month settlement).

- **Hours bank**: closing balance = opening + contracted - actual, shown in the
  summary card. `PATCH .../opening-balance` changes the opening balance and
  rewrites the body's billing summary from it (`BillingSummary`, which finds
  the section by the markdown parser's headings).
- **One implementation**: `App\Services\Clients\HoursBank`. `openingBalance()`
  is the carry-in: the closing balance of the latest settled point that ended
  before the period, by period end. A month's closure beats a report ending
  the same day; of reports sharing a period: sent, then finalized, then draft,
  then the newest row (`ClientReport::governing()`), so a rounded
  `actual_hours` carries as billed. From the account's `settlement_from` on
  only whole-month reports settle; older partial reports still carry.
  `month()` and `months([clientId => period])` (batched per distinct period)
  give a month's figures for every screen, in this order: the month's closure
  snapshot; else a report covering exactly that month (opening, pool and cap;
  its `actual_hours` counts once it is finalized or sent or the month has
  ended, so a draft for the month in progress never freezes the hours); else
  reportable time in the local month against the retainer the report would
  snapshot (the leading active position on the 1st; `effective_to` is
  exclusive; `has_retainer` is false without one). `source` says which applied
  (`closure`, `report`, `entries`); logged time is always live. The client
  overview and `crm brief` read it; the brief keeps every logged hour of the
  calendar month as `hours.month`, labelled `month_counts: all_logged_time`.
- Generate and regenerate store the opening balance and cap the body was
  composed from. Regenerate keeps the saved opening balance and carries one in
  from the previous settled point only with `recalculate_opening_balance`, or
  always on a report flagged `needs_recalculation`.
- A report with `contracted_hours` null was billed outside a retainer: its closing balance equals its opening, so its hours never become bank debt for a later retainer. Closing such a month freezes that opening too (the snapshot carries `outside_bank: true`, which the closed month reads back).
- Rounding a month's hours is done on the report's `actual_hours`
  (`reports:generate --hours=`), never by padding a time entry.

**Status**: draft, then finalized, then sent, and Reopen back to draft. Every
status change goes through `App\Services\Reports\ReportStatusTransition`
(`finalize`, `markSent`, `reopen`), which locks the client row and then the
report row (`App\Services\Clients\ClientFinancialLock`) and decides on the
stored status. Every report write takes that lock in that order: generate (web
and `reports:generate`), body edits, the opening balance, regenerate, delete and
the status moves.

| From \ to | finalized | sent | draft (reopen) |
| --- | --- | --- | --- |
| draft | sets `finalized_at` | refused: finalize first | no-op |
| finalized | no-op | sets `sent_at` once | clears `finalized_at` |
| sent | no-op, stays sent | no-op, keeps `sent_at` | clears `finalized_at` and `sent_at` |

A no-op writes no revision and says so in the flash, so a stale finalize from
an old tab never demotes a sent report. A rule outside this table (a closed
period, a flagged report) is a private refusal check in `ReportStatusTransition`;
it runs under the same lock and can only refuse. Finalizing or sending is also
refused while a timer of the client that started on or before the report's
last day is still running, as closing a month is.

`client_report_revisions` is append-only. Every update, regenerate, finalize,
sent, reopen and opening-balance change first snapshots the previous body,
status, `finalized_at`, `sent_at`, hours, opening balance, cap, currency and
composer key with the user, through
`ClientReport::recordRevision()` in the same transaction. That history is what
makes "edit any time" safe: there are no editability locks, and deleting a
report deletes its revisions.

**Concurrent edits**: each write locks the row and carries the version the
editor started from (`ClientReport::version()`, a hash of body and opening
balance). A write based on an older version is refused. Every figure-changing
write goes through `App\Services\Reports\ReportWriter` (generate for the web
and `reports:generate`, regenerate, body edits, opening edits, delete).
Composing can take an AI call, so it runs before the lock; under the lock the
writer checks the settlement rules again and recomputes the figures
(`ReportContext::figures()`: closure, contracted, opening, cap, actual), and
regenerate also compares the version and the status. Anything that moved
meanwhile refuses the write ("The figures changed, try again").

## Month settlement (Zamknij miesiąc)

A financial close freezes one client month in the hours bank. It is separate
from the month-close checklist (`month-close.md`) and from report delivery: a
month can be closed while its report is unsent.

- **Baseline**: `Account::settlementFrom()` is the only reader of the account
  setting `settlement_from` (YYYY-MM). Accounts that existed when it shipped
  were backfilled with the literal `2026-09`; an account without it starts in
  its local creation month. It is never recomputed from the clock.
- **Eligible month**: a month from the baseline up to, not including, the
  current local month, in which a retainer position leads on the 1st (active,
  `effective_to` exclusive), or which was ever closed (a settlement event
  exists, so a reopened month keeps its obligation whatever happened to the
  position since).
- **Active month**: the oldest eligible month without a closure, else the
  current month. `HoursBank::activeMonth()` / `settlement()` /
  `activeMonths()` (batched) take one clock per call. Gaps are skipped; on
  1 November an unclosed September stays active; ending a retainer only stops
  future months from becoming eligible, its elapsed months stay owed.
- **Close** (`POST /clients/{client}/months/{period}/close` with
  `preview_hash`) through `App\Services\Clients\MonthSettlement` under
  `ClientFinancialLock`. In order: an existing closure is a no-op; the month
  must be strictly past; no later month may be closed; it must be eligible and
  the active one; no timer may still run
  in it; no report before it (its own included) may be flagged
  `needs_recalculation`; no finalized or sent report may come after it; and the
  snapshot must hash to what the dialog showed (`MonthSettlement::preview()`).
  The snapshot (`schema_version` 1) takes a governing whole-month report's
  figures whatever its status (`source: report`), else live reportable time
  (`source: entries`), and also stores `entries` (live reportable hours, for
  the dialog's info line) and `logged`. Hours are rounded to 2 places; null
  means not applicable. The redirect flashes the sentence (hours in the app
  locale, `App\Support\HoursText`: one decimal, a second only when needed,
  "−16,0 h" / "−0,25 h" in Polish) and
  `flash.settlement` (`action`, `period`, `closing`, `next`, `recalculate`) for
  the toast to word itself.
- **Reopen** (`DELETE` the same URL with `closure_id`): only the latest
  closure, refused when a later report is finalized or sent. A `closure_id`
  that no longer exists is a no-op, so a late retry never reopens a newer close
  of the same month.
- **Audit**: every close and reopen writes exactly one
  `client_month_settlement_events` row with the figures it froze or
  discarded; the model refuses updates and deletes. The rows go only with a
  hard client delete (cascade); clients are soft-deleted, so archiving keeps
  them.
- **Recalculation**: closing or reopening a month, every allowed figure change
  to a report, and a status move that changes which report settles a period,
  set `needs_recalculation` on every later draft. "Later" and "earlier" are
  read from the report's own dates (a later report starts after this one's
  `period_end`), so a chain of legacy partials inside one month is covered;
  closures keep whole-month boundaries.
  No later report is ever written automatically. A flagged report regenerates
  with its opening carried in again, which clears the flag; until then it, and
  every report after it, can be neither finalized nor sent, its month cannot
  close, and no report after it can be generated or recalculated. Recalculation
  therefore runs oldest first. A regenerate that finds the flag changed under
  the lock saves nothing.
- **Which report settles a day**: `ClientReport::settling()`, the one
  selection the hours bank, the guards, the status move and the brief's
  `latest_report` use: from the baseline on only whole months, older partials
  still, then `governing()`. `HoursBank::ledgerPage()` reads figures only for
  the rows on the page.

**Closed-period policy** (`App\Services\Clients\SettlementPolicy`, the
`ReportWriter` checks and the refusal checks in `ReportStatusTransition`):

| Action on P's report | P's month closed, or a closure after it | A later report finalized or sent | An earlier report flagged | Otherwise |
| --- | --- | --- | --- | --- |
| Generate (web, `reports:generate`) | P closed: allowed from the closure's figures; `--hours` refused. Closure after P: refused | refused | refused | allowed, later drafts flagged |
| Regenerate | refused: reopen first | refused | refused | allowed, later drafts flagged |
| Opening balance edit | refused | refused | refused | allowed, later drafts flagged |
| Delete | refused | refused | refused | allowed, later drafts flagged |
| Body edit | allowed; the billing summary is re-rendered from the closure, a body without exactly one is rejected | allowed | allowed | allowed as written |
| Finalize, mark sent | allowed | allowed | refused | refused while P's own report is flagged |
| Reopen (report) | allowed | allowed | allowed | allowed |

A status move that hands a period to another report ending the same day (a
legacy duplicate) is treated as a figure change: refused like one, and when
allowed it flags the later drafts.

**Invoicing is not frozen.** `infakt:draft-invoice` (`InfaktDraftRun`) keeps
billing live month-end fees from the retainer positions; a closure freezes the
hours bank and reports only.

**Allowed live inputs.** Time entries, tasks, agent logging, timers, Trello
sync and `crm:humanize` (report prose) never rewrite a closure. A closed
month's `used` stays frozen; later edits change only its logged time.
Changing the numbers is stricter: the time edit dialog, its delete,
the manual entry form, `time:log`, `time-edit` and `time-split` all go
through `TimeEntryWriter`, which refuses a start, end, minutes, task or
billable change, a delete and a new entry in a closed month or under a
finalized or sent report, while the title and description stay editable
(`period_settled` for agents, a 422 the dialog shows for the owner;
`agent-crm-interface.md`).

**Retainer positions** (`App\Services\Clients\RetainerTerms`): adding,
editing, deactivating or deleting a position runs under the client lock and is
rolled back when it would change what leads on the 1st (the position, its
hours or its cap, or whether one leads at all) of any elapsed eligible month,
closed or not, or of any month from the baseline through the latest closure
("change the retainer from next month on"). So a position backdated into a
month before a closed one is refused, while one backdated into an open backlog
with no closure after it is saved and adds that month to the backlog.
`effective_to` is editable on update: it may be set, moved or cleared only
when both the stored and the new date are on or after the current month's 1st
(clearing a future end renews the position); a position that already ended
stays ended.

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
Finalize (a draft), Oznacz jako wysłany and Reopen (finalized), Reopen (sent,
whose status reads "wysłany DD.MM" in local time), Copy, Print, Delete. The change history at the bottom
expands update and regenerate rows into a line diff (`<MarkdownDiff>`): each
row shows what that edit changed.

`php artisan reports:generate {client} [--period= --hours= --composer=
--locale=]` drafts one from the command line.

Routes: `POST|DELETE /clients/{client}/months/{period}/close`, `POST /clients/{client}/reports`, `GET/PUT/DELETE
/clients/{client}/reports/{report}`, `POST
/clients/{client}/reports/{report}/regenerate|finalize|mark-sent|reopen`, `PATCH
/clients/{client}/reports/{report}/opening-balance`, `PUT
/clients/{client}/report-baseline`. `PATCH /tasks/{task}/reportable` exists
but nothing in the UI calls it; the flag is saved through the task form.

Money and hours on the page format through `useFormatters()` in
`resources/js/lib/format.ts`.

The client record's Rozliczenia tab (`ClientBillingQuery`) reads its rows from
`HoursBank::ledgerPage()` twelve months at a time, so only the shown months have
their figures read; "Starsze" reloads only `billing_ledger` with `?miesiace=N`
and the rows are appended, closure-only months included, until the last page
ends on the entry row. A client with nothing to settle in the month in progress
(no retainer, report or closure) gets no row for it. The rows are closed months, governing report months and the active
month, newest first. A whole month reads from `HoursBank::month()` (a closed
month from its snapshot), so its figures match every other screen, and a draft
for the running month counts live time. Each row carries its settlement and its
report delivery as separate fields: `settlement` is `closed` only from a real
closure (with `closed_on`), `open` for a month that owes a close or is the
active one, and null before the baseline or in a gap, where only the report
status shows. "Otwórz ponownie" sits on the latest closed row only
(`closure_id`, with `reopen_refusal` when a later report is already out). The
headline leads with the latest closure when the active month comes after it
("Wrzesień zamknięty 05.10 na 32 z 30 h, ... Październik otwarty: ..."); an
ended month without a closure reads "skończył się", never "zamknął się". Zmień
bilans otwarcia patches the newest report's opening balance and is offered only
while `SettlementPolicy::reportFigureRefusal()` allows that change. A client
without a retainer, a closure or a report gets an empty state that points to
generating one. Overage never shows a cost.

**Settlement surfaces.** The confirm dialog (`month-settlement/close-month-dialog`,
shared by Przegląd and the month-close page) fetches
`GET /clients/{client}/months/{period}/close`, which answers
`MonthSettlement::preview()` as JSON (figures, `source` and `report_status` for
the line "z raportu (szkic)" / "z raportu" / "z wpisów", live `entries` for the
"Wpisy dziś" info line, the `hash` it posts back, and the `refusal` that
disables the button). `FlashMessages` words the close and reopen toasts from
`flash.settlement` (`resources/js/lib/settlement-toast.ts`): "Wrzesień
zamknięty" over "Bilans −11,5 h przechodzi na październik.", or "Wrzesień znów
otwarty" over the active-month line, plus the recalculation ask when a later
draft was flagged. Every Solo hours figure goes through
`resources/js/lib/hours-text.ts`, the client twin of `HoursText` (both run the
fixtures in `tests/Fixtures/hours-text.json`): worked hours and balances keep one
decimal and a second only when needed, retainer terms (package, rollover cap)
read as entered through `hoursTerms` / `HoursText::terms()`. Przegląd's hours
block is always the active month's, as on Lista; while a month is open behind it
carries the note "Jest już październik · wrzesień jeszcze otwarty" and an
outlined "Zamknij miesiąc". The waiting-report reminder is separate and never
moves the block. Lista reads each row's active
month (`HoursBank::settlements()` plus `months()`, a query count per distinct
period, never per client), marks rows behind with a short month ("WRZ") and
puts an account-wide note over the table, counting non-archived clients behind
and naming the month when they all share one. Dziennik renders closes and
reopens from `client_month_settlement_events` in the relationship rail,
interleaved by time with the entries of each page; they are read-only.
